<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Arcp\Envelope\MessageType;
use Arcp\Errors\DeadlineExceededException;
use Arcp\Ids\MessageId;

/**
 * Routes correlated responses back to their `await`ing fiber (RFC §6.3).
 * Each pending entry holds a {@see DeferredFuture}; resolution is keyed
 * on the inbound envelope's `correlation_id` matching the outbound `id`.
 */
final class PendingRegistry
{
    /** @var array<string, DeferredFuture<MessageType>> */
    private array $waiters = [];

    public function awaitResponse(
        MessageId $id,
        ?float $deadlineSeconds = null,
        ?Cancellation $cancellation = null,
    ): MessageType {
        $deferred = new DeferredFuture();
        $this->waiters[(string) $id] = $deferred;

        $cancellations = [];
        if ($deadlineSeconds !== null) {
            $cancellations[] = new TimeoutCancellation($deadlineSeconds);
        }
        if ($cancellation !== null) {
            $cancellations[] = $cancellation;
        }
        $combined = $cancellations === [] ? null : new CompositeCancellation(...$cancellations);

        try {
            /** @var MessageType $result */
            $result = $deferred->getFuture()->await($combined);
            return $result;
        } catch (\Amp\TimeoutException $e) {
            throw new DeadlineExceededException(
                \sprintf('await(%s) deadline of %.3fs exceeded', $id, $deadlineSeconds ?? 0.0),
                ['correlation_id' => (string) $id],
                null,
                $e,
            );
        } finally {
            unset($this->waiters[(string) $id]);
        }
    }

    public function resolve(MessageId $correlationId, MessageType $value): bool
    {
        $key = (string) $correlationId;
        if (!isset($this->waiters[$key])) {
            return false;
        }
        $w = $this->waiters[$key];
        unset($this->waiters[$key]);
        $w->complete($value);
        return true;
    }

    public function failAll(\Throwable $reason): void
    {
        foreach ($this->waiters as $deferred) {
            $deferred->error($reason);
        }
        $this->waiters = [];
    }

    public function pendingCount(): int
    {
        return \count($this->waiters);
    }
}
