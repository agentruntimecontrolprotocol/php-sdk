<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Ids\MessageId;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Subscriptions\JobSubscribe;
use Arcp\Messages\Subscriptions\JobSubscribed;
use Arcp\Messages\Subscriptions\JobUnsubscribe;
use Arcp\Messages\Subscriptions\SubscribeClosed;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Job;
use Arcp\Runtime\Session;
use Arcp\Runtime\Subscription;

/**
 * Handles `job.subscribe` / `job.unsubscribe` (ARCP v1.1 §7.6):
 * authorization, history replay (`from_event_seq` + `history`), and
 * detach.
 *
 * @internal
 */
final readonly class SubscriptionRouter
{
    public function __construct(
        private ARCPRuntime $runtime,
        private LifecycleHandler $lifecycle,
    ) {
    }

    public function subscribe(Session $session, Envelope $env, JobSubscribe $msg): void
    {
        $job = $this->runtime->jobs->tryGet($msg->jobId);
        if (!$job instanceof Job) {
            // §12: the job does not exist or is not visible.
            $this->lifecycle->nack($session, $env, 'JOB_NOT_FOUND', 'job not found');
            return;
        }
        if (!$this->authorized($session, $job)) {
            // §7.6: unauthorized subscription returns PERMISSION_DENIED.
            $this->lifecycle->nack(
                $session,
                $env,
                'PERMISSION_DENIED',
                'principal may not observe this job',
            );
            return;
        }
        $sub = $this->runtime->subscriptions->compile($session, $msg);
        $replayed = false;
        if ($msg->history && !$this->backfill($session, $sub, $msg, $replayed)) {
            return;
        }
        $this->runtime->emit($session, new JobSubscribed(
            jobId: $job->id,
            currentStatus: $job->state->value,
            agent: $job->toolRef(),
            traceId: $job->invocation->traceId,
            subscribedFrom: $session->currentEventSeq(),
            replayed: $replayed,
        ), [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
            'subscription_id' => $sub->id,
        ]);
        $this->emitBackfillComplete($session, $sub, $job);
    }

    public function unsubscribe(Session $session, Envelope $env, JobUnsubscribe $msg): void
    {
        $sub = $this->runtime->subscriptions->findForJob($session, $msg->jobId);
        if ($sub instanceof Subscription) {
            $this->runtime->subscriptions->close($sub->id);
        }
        // §7.6 defines no acknowledgement for job.unsubscribe; detach is
        // silent and idempotent.
    }

    /**
     * §7.6: principals that submitted the job are always permitted; the
     * single-tenant runtime otherwise restricts observation to the same
     * principal.
     */
    private function authorized(Session $session, Job $job): bool
    {
        if ($job->session === $session) {
            return true;
        }
        return $session->principal !== null && $session->principal === $job->session->principal;
    }

    /**
     * Replay buffered events for the subscribed job with
     * `seq > from_event_seq` (§7.6). Returns false if the backfill could
     * not complete and the subscription has been closed.
     */
    private function backfill(
        Session $session,
        Subscription $sub,
        JobSubscribe $msg,
        bool &$replayed,
    ): bool {
        try {
            foreach ($this->runtime->eventLog->replayAfter('') as $past) {
                if (!$sub->matches($past)) {
                    continue;
                }
                if (
                    $msg->fromEventSeq !== null
                    && ($past->eventSeq === null || $past->eventSeq <= $msg->fromEventSeq)
                ) {
                    continue;
                }
                $replayed = true;
                $session->transport->send(new Envelope(
                    id: MessageId::random(),
                    payload: new SubscribeEvent(
                        $this->runtime->serializer->envelopeToArray($past),
                    ),
                    timestamp: $this->runtime->clock->now(),
                    priority: $past->priority,
                    sessionId: $session->sessionId,
                    jobId: $past->jobId,
                    subscriptionId: $sub->id,
                ));
            }
        } catch (\Throwable $e) {
            $this->runtime->emit(
                $session,
                new SubscribeClosed('RESUME_WINDOW_EXPIRED', $e->getMessage()),
                ['subscription_id' => $sub->id, 'job_id' => $msg->jobId],
            );
            $this->runtime->subscriptions->close($sub->id);
            return false;
        }
        return true;
    }

    private function emitBackfillComplete(Session $session, Subscription $sub, Job $job): void
    {
        // §8.2: implementation-defined `status` phase marking the end of
        // the §7.6 history replay. Emitted as a regular sequenced
        // job.event so the subscription wrapper carries an event_seq from
        // the session's normal sequence space (§8.3).
        $this->runtime->emit($session, new JobEvent(
            'status',
            $this->runtime->clock->now(),
            ['phase' => 'backfill_complete'],
        ), ['subscription_id' => $sub->id, 'job_id' => $job->id]);
    }
}
