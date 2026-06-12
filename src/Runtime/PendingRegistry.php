<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\TimeoutException as AmpTimeoutException;
use Arcp\Envelope\MessageType;
use Arcp\Errors\TimeoutException;
use Arcp\Ids\MessageId;
use Revolt\EventLoop;

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
        /** @var DeferredFuture<MessageType> $deferred */
        $deferred = new DeferredFuture();
        $this->waiters[(string) $id] = $deferred;

        $timedOut = false;
        $timer = $this->scheduleDeadline($deferred, $deadlineSeconds, $timedOut);
        $cancelCallback = $this->subscribeCancellation($deferred, $cancellation);
        try {
            /** @var MessageType $result */
            $result = $deferred->getFuture()->await();
            return $result;
        } catch (AmpTimeoutException $e) {
            throw new TimeoutException(
                \sprintf('await(%s) deadline of %.3fs exceeded', $id, $deadlineSeconds ?? 0.0),
                ['correlation_id' => (string) $id],
                null,
                $e,
            );
        } finally {
            if ($timer !== null && !$timedOut) {
                EventLoop::cancel($timer);
            }
            if ($cancelCallback !== null && $cancellation instanceof Cancellation) {
                $cancellation->unsubscribe($cancelCallback);
            }
            unset($this->waiters[(string) $id]);
        }
    }

    /**
     * Schedule our own referenced timer for the deadline. Amp's
     * TimeoutCancellation uses an unreferenced timer, which causes the
     * event loop to exit when every fiber happens to be suspended on a
     * DeferredFuture (no other referenced work). A referenced delay
     * keeps the loop alive long enough for the timeout to fire.
     *
     * @param DeferredFuture<MessageType> $deferred
     */
    private function scheduleDeadline(
        DeferredFuture $deferred,
        ?float $deadlineSeconds,
        bool &$timedOut,
    ): ?string {
        if ($deadlineSeconds === null) {
            return null;
        }
        return EventLoop::delay(
            $deadlineSeconds,
            function () use (&$timedOut, $deferred): void {
                if (!$deferred->isComplete()) {
                    $timedOut = true;
                    $deferred->error(new AmpTimeoutException('deadline exceeded'));
                }
            },
        );
    }

    /** @param DeferredFuture<MessageType> $deferred */
    private function subscribeCancellation(
        DeferredFuture $deferred,
        ?Cancellation $cancellation,
    ): ?string {
        if (!$cancellation instanceof Cancellation) {
            return null;
        }
        return $cancellation->subscribe(
            function (\Throwable $reason) use ($deferred): void {
                if (!$deferred->isComplete()) {
                    $deferred->error($reason);
                }
            },
        );
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
