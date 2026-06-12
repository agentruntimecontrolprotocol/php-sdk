<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SubscriptionId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Subscriptions\JobSubscribe;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Holds active job subscriptions (ARCP v1.1 §7.6) and dispatches
 * matching envelopes through `subscribe.event` wrappers. History replay
 * runs from the event log up to the current write head, then concludes
 * with a synthetic `subscription.backfill_complete` event before live
 * tail begins.
 */
final class SubscriptionManager
{
    /** @var array<string, Subscription> */
    private array $byId = [];

    private readonly ClockInterface $clock;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EnvelopeSerializer $serializer,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
    }

    public function compile(Session $session, JobSubscribe $msg): Subscription
    {
        // §7.6: a subscription is scoped to a single job; the job may have
        // been submitted by a different session of the same principal, so
        // the filter intentionally carries no session constraint.
        $sub = new Subscription(
            SubscriptionId::random(),
            $session,
            new SubscriptionFilter(jobIds: [(string) $msg->jobId]),
        );
        $this->byId[(string) $sub->id] = $sub;
        return $sub;
    }

    /**
     * Find this session's subscription for `$jobId`, if any. Used by
     * `job.unsubscribe` (§7.6), which addresses subscriptions by job.
     */
    public function findForJob(Session $session, JobId $jobId): ?Subscription
    {
        foreach ($this->byId as $sub) {
            if (
                $sub->session === $session
                && \in_array((string) $jobId, $sub->filter->jobIds, true)
            ) {
                return $sub;
            }
        }
        return null;
    }

    public function close(SubscriptionId $id): bool
    {
        $key = (string) $id;
        if (!isset($this->byId[$key])) {
            return false;
        }
        unset($this->byId[$key]);
        return true;
    }

    public function get(SubscriptionId $id): ?Subscription
    {
        return $this->byId[(string) $id] ?? null;
    }

    public function dispatch(Envelope $env): void
    {
        /** @var list<SubscriptionId> $failed */
        $failed = [];
        foreach ($this->byId as $sub) {
            if (!$sub->matches($env)) {
                continue;
            }
            $wrapper = new SubscribeEvent(
                $this->serializer->envelopeToArray($env),
            );
            $session = $sub->session;
            // Direct send: bypass dispatcher because subscriptions are pure
            // delivery from the runtime's perspective (RFC §13).
            try {
                $session->transport->send(new Envelope(
                    id: MessageId::random(),
                    payload: $wrapper,
                    timestamp: $this->clock->now(),
                    priority: $env->priority,
                    sessionId: $session->sessionId,
                    jobId: $env->jobId,
                    subscriptionId: $sub->id,
                ));
            } catch (\Throwable $e) {
                // Collect failures and close after iterating so the loop does
                // not mutate $byId mid-traversal; surface the cause.
                $this->logger->warning('subscription dispatch failed; closing subscription', [
                    'subscription_id' => (string) $sub->id,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $failed[] = $sub->id;
            }
        }
        foreach ($failed as $id) {
            $this->close($id);
        }
    }

    public function count(): int
    {
        return \count($this->byId);
    }
}
