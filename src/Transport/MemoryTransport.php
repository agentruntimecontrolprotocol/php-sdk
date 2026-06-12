<?php

declare(strict_types=1);

namespace Arcp\Transport;

use function Amp\async;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Arcp\Envelope\Envelope;
use Arcp\Errors\TransportClosedException;

/**
 * In-process loopback transport. A single call to {@see MemoryTransport::pair()}
 * returns the two endpoints of a bidirectional channel; what one writes,
 * the other reads, in FIFO order. Used by every integration test that
 * doesn't need a real socket.
 */
final class MemoryTransport implements Transport
{
    /** @var \SplQueue<Envelope> */
    private readonly \SplQueue $inbox;

    /** @var \SplQueue<DeferredFuture<Envelope|null>> */
    private readonly \SplQueue $waiters;

    /**
     * Object ids of waiters cancelled while still queued. They are skipped
     * lazily at dequeue time so cancellation does not rebuild the queue.
     *
     * @var array<int, true>
     */
    private array $cancelledWaiters = [];

    private bool $closed = false;
    private ?MemoryTransport $peer = null;

    private function __construct()
    {
        /** @var \SplQueue<Envelope> $q */
        $q = new \SplQueue();
        $this->inbox = $q;
        /** @var \SplQueue<DeferredFuture<Envelope|null>> $w */
        $w = new \SplQueue();
        $this->waiters = $w;
    }

    /**
     * @return array{0: MemoryTransport, 1: MemoryTransport}
     */
    public static function pair(): array
    {
        $a = new self();
        $b = new self();
        $a->peer = $b;
        $b->peer = $a;
        return [$a, $b];
    }

    #[\Override]
    public function send(Envelope $env, ?Cancellation $cancellation = null): void
    {
        if ($this->closed) {
            throw new TransportClosedException('memory transport closed');
        }
        $peer = $this->peer ?? throw new TransportClosedException('memory transport unpaired');
        $peer->deliver($env);
    }

    private function deliver(Envelope $env): void
    {
        while (!$this->waiters->isEmpty()) {
            $waiter = $this->waiters->dequeue();
            $oid = spl_object_id($waiter);
            if (isset($this->cancelledWaiters[$oid])) {
                unset($this->cancelledWaiters[$oid]);

                continue;
            }
            $waiter->complete($env);
            return;
        }
        $this->inbox->enqueue($env);
    }

    #[\Override]
    public function receive(?Cancellation $cancellation = null): ?Envelope
    {
        if (!$this->inbox->isEmpty()) {
            return $this->inbox->dequeue();
        }
        if ($this->closed) {
            return null;
        }
        /** @var DeferredFuture<Envelope|null> $deferred */
        $deferred = new DeferredFuture();
        $this->waiters->enqueue($deferred);
        try {
            /** @var Envelope|null $result */
            $result = $deferred->getFuture()->await($cancellation);
            return $result;
        } catch (\Throwable $e) {
            // Tombstone this waiter so deliver() skips it at dequeue time
            // rather than rebuilding the whole queue on every cancellation.
            $this->cancelledWaiters[spl_object_id($deferred)] = true;
            throw $e;
        }
    }

    /**
     * Receive in the background; useful for setting up two-sided test scenarios.
     *
     * @return Future<Envelope|null>
     */
    public function receiveAsync(?Cancellation $cancellation = null): Future
    {
        /** @var Future<Envelope|null> $future */
        $future = async(fn (): ?Envelope => $this->receive($cancellation));
        return $future;
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->completePendingWaitersWithEof();
        if ($this->peer instanceof \Arcp\Transport\MemoryTransport && !$this->peer->closed) {
            // Propagate close to the peer: clear the peer link and resolve
            // any pending receive() waiters with EOF.
            $peer = $this->peer;
            $this->peer = null;
            $peer->signalClosed();
        }
    }

    /**
     * Mark this endpoint closed: drop the peer link and resolve any pending
     * receive() waiters with a clean EOF. Invoked on the peer instance when
     * the other end closes so the side effect is named and localized.
     */
    private function signalClosed(): void
    {
        $this->peer = null;
        $this->closed = true;
        $this->completePendingWaitersWithEof();
    }

    /** Resolve every still-queued, non-cancelled waiter with a clean EOF. */
    private function completePendingWaitersWithEof(): void
    {
        while (!$this->waiters->isEmpty()) {
            $waiter = $this->waiters->dequeue();
            $oid = spl_object_id($waiter);
            if (isset($this->cancelledWaiters[$oid])) {
                unset($this->cancelledWaiters[$oid]);

                continue;
            }
            $waiter->complete();
        }
        $this->cancelledWaiters = [];
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
