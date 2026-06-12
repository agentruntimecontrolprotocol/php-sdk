<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SubscriptionId;
use Arcp\Messages\Control\Ack;
use Arcp\Messages\Subscriptions\Subscribe;
use Arcp\Messages\Subscriptions\SubscribeAccepted;
use Arcp\Messages\Subscriptions\SubscribeClosed;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Session;
use Arcp\Runtime\Subscription;
use Arcp\Version;

/**
 * Handles subscribe/unsubscribe ARCP requests: authorization scoping,
 * compilation, backfill (RFC §13.3), and close.
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

    public function subscribe(Session $session, Envelope $env, Subscribe $msg): void
    {
        if (!$this->authorizeFilter($session, $env, $msg)) {
            return;
        }
        $sub = $this->runtime->subscriptions->compile($session, $msg);
        $this->runtime->emit($session, new SubscribeAccepted($sub->id), [
            'correlation_id' => $env->id,
            'subscription_id' => $sub->id,
        ]);
        if (!$this->backfill($session, $sub, $msg)) {
            return;
        }
        $this->emitBackfillComplete($session, $sub->id);
    }

    public function unsubscribe(Session $session, Envelope $env): void
    {
        if (!$env->subscriptionId instanceof SubscriptionId) {
            $this->lifecycle->nack(
                $session,
                $env,
                'INVALID_REQUEST',
                'unsubscribe missing subscription_id',
            );
            return;
        }
        $closed = $this->runtime->subscriptions->close($env->subscriptionId);
        $this->runtime->emit($session, new Ack($closed ? 'closed' : 'unknown'), [
            'correlation_id' => $env->id,
        ]);
    }

    /**
     * Authorization: a subscriber may only observe sessions they own
     * (their own session id, in the current single-tenant model). Returns
     * true if the filter is in scope, false (and emits a nack) otherwise.
     */
    private function authorizeFilter(Session $session, Envelope $env, Subscribe $msg): bool
    {
        $sid = $session->sessionId instanceof SessionId ? (string) $session->sessionId : null;
        $requested = $msg->filter['session_id'] ?? null;
        if (\is_array($requested)) {
            foreach ($requested as $r) {
                if ($sid !== null && \is_string($r) && $r !== $sid) {
                    return $this->denyOutOfScope($session, $env);
                }
            }
            return true;
        }
        if (\is_string($requested) && $sid !== null && $requested !== $sid) {
            return $this->denyOutOfScope($session, $env);
        }
        return true;
    }

    private function denyOutOfScope(Session $session, Envelope $env): bool
    {
        $this->lifecycle->nack(
            $session,
            $env,
            'PERMISSION_DENIED',
            'subscription session_id outside scope',
        );
        return false;
    }

    /**
     * Replay matching log entries to the new subscriber. Returns false if
     * the backfill could not complete and the subscription has been
     * closed with DATA_LOSS.
     */
    private function backfill(Session $session, Subscription $sub, Subscribe $msg): bool
    {
        $after = $msg->sinceMessageId ?? '';
        $sessionId = $session->sessionId;
        try {
            $stream = $sessionId instanceof SessionId
                ? $this->runtime->eventLog->replayAfterForSession($after, $sessionId)
                : $this->runtime->eventLog->replayAfter($after);
            foreach ($stream as $past) {
                if (!$sub->matches($past)) {
                    continue;
                }
                $session->transport->send(new Envelope(
                    id: MessageId::random(),
                    payload: new SubscribeEvent(
                        $this->runtime->serializer->envelopeToArray($past),
                    ),
                    timestamp: $this->runtime->clock->now(),
                    priority: $past->priority,
                    sessionId: $session->sessionId,
                    subscriptionId: $sub->id,
                ));
            }
        } catch (\Throwable $e) {
            $this->runtime->emit(
                $session,
                new SubscribeClosed('RESUME_WINDOW_EXPIRED', $e->getMessage()),
                ['subscription_id' => $sub->id],
            );
            $this->runtime->subscriptions->close($sub->id);
            return false;
        }
        return true;
    }

    private function emitBackfillComplete(Session $session, SubscriptionId $subId): void
    {
        $timestamp = $this->runtime->clock->now()->format(\DateTimeInterface::RFC3339_EXTENDED);
        $this->runtime->emit($session, new SubscribeEvent([
            'arcp' => Version::PROTOCOL_VERSION,
            'id' => (string) MessageId::random(),
            'type' => 'event.emit',
            'timestamp' => $timestamp,
            'payload' => new EventEmit('subscription.backfill_complete')->toArray(),
        ]), ['subscription_id' => $subId]);
    }
}
