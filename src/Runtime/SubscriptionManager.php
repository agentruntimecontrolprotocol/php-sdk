<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Envelope\Priority;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\MessageId;
use Arcp\Ids\SubscriptionId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Subscriptions\Subscribe;
use Arcp\Messages\Subscriptions\SubscribeEvent;

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

    public function __construct(private readonly EnvelopeSerializer $serializer)
    {
    }

    public function compile(Session $session, Subscribe $msg): Subscription
    {
        $sessionIds = $this->extractStringList($msg->filter, 'session_id');
        $traceIds   = $this->extractStringList($msg->filter, 'trace_id');
        $jobIds     = $this->extractStringList($msg->filter, 'job_id');
        $streamIds  = $this->extractStringList($msg->filter, 'stream_id');
        $types      = $this->extractStringList($msg->filter, 'types');
        $min = Priority::Low;
        if (isset($msg->filter['min_priority']) && \is_string($msg->filter['min_priority'])) {
            $min = Priority::tryFrom($msg->filter['min_priority']) ?? throw new InvalidArgumentException(
                'min_priority not recognized',
                ['min_priority' => $msg->filter['min_priority']],
            );
        }
        $sub = new Subscription(
            SubscriptionId::random(),
            $session,
            $sessionIds,
            $traceIds,
            $jobIds,
            $streamIds,
            $types,
            $min,
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
                    timestamp: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    priority: $env->priority,
                    sessionId: $session->sessionId,
                    subscriptionId: $sub->id,
                ));
            } catch (\Throwable) {
                $this->close($sub->id);
            }
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
            throw new InvalidArgumentException("filter.$key must be string or list of strings");
        }
        $out = [];
        foreach ($val as $entry) {
            if (!\is_string($entry)) {
                throw new InvalidArgumentException("filter.$key entries must be strings");
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
