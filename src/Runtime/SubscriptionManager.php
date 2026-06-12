<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\Priority;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SubscriptionId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Subscriptions\Subscribe;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Holds active subscriptions and dispatches matching envelopes through
 * `subscribe.event` wrappers (RFC §13). Backfill replays from the event
 * log up to the current write head, then concludes with a synthetic
 * `subscription.backfill_complete` event before live tail begins.
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

    public function compile(Session $session, Subscribe $msg): Subscription
    {
        $sessionIds = $this->extractStringList($msg->filter, 'session_id');
        // Default the session_id constraint to the calling session if no
        // filter was supplied. Without this, an empty list means "match any
        // session" and a subscriber would observe every other session's
        // envelopes (RFC §13 — subscriptions are session-scoped).
        if ($sessionIds === [] && $session->sessionId instanceof SessionId) {
            $sessionIds = [(string) $session->sessionId];
        }
        $traceIds   = $this->extractStringList($msg->filter, 'trace_id');
        $jobIds     = $this->extractStringList($msg->filter, 'job_id');
        $streamIds  = $this->extractStringList($msg->filter, 'stream_id');
        $types      = $this->extractStringList($msg->filter, 'types');
        $min = null;
        if (isset($msg->filter['min_priority']) && \is_string($msg->filter['min_priority'])) {
            $min = Priority::tryFrom($msg->filter['min_priority'])
                ?? throw new InvalidRequestException(
                    'min_priority not recognized',
                    ['min_priority' => $msg->filter['min_priority']],
                );
        }
        $sub = new Subscription(
            SubscriptionId::random(),
            $session,
            new SubscriptionFilter($sessionIds, $traceIds, $jobIds, $streamIds, $types, $min),
        );
        $this->byId[(string) $sub->id] = $sub;
        return $sub;
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

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<string>
     */
    private function extractStringList(array $filter, string $key): array
    {
        if (!isset($filter[$key])) {
            return [];
        }
        $val = $filter[$key];
        if (\is_string($val)) {
            return [$val];
        }
        if (!\is_array($val)) {
            throw new InvalidRequestException("filter.$key must be string or list of strings");
        }
        $out = [];
        foreach ($val as $entry) {
            if (!\is_string($entry)) {
                throw new InvalidRequestException("filter.$key entries must be strings");
            }
            $out[] = $entry;
        }
        return $out;
    }

    public function count(): int
    {
        return \count($this->byId);
    }
}
