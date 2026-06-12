<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Ids\SessionId;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Transport\Transport;

/**
 * Per-session in-memory state. The runtime keeps one of these per active
 * peer (RFC §9). Mutated only by the {@see ARCPRuntime} dispatch loop and
 * by the per-session managers (jobs, streams, subscriptions, leases).
 */
final class Session
{
    public SessionState $state = SessionState::Opening;

    /** @var array<string, mixed> */
    public array $context = [];

    public ?SessionId $sessionId = null;
    public ?string $principal = null;
    public ?PeerInfo $peerInfo = null;
    public ?Capabilities $capabilities = null;

    /**
     * Highest `event_seq` the peer reported processing via `session.ack`
     * (§6.5). Null until the first ack arrives. Advisory in this phase;
     * buffered-event release keys off it in a later phase.
     */
    public ?int $lastAckedEventSeq = null;

    /**
     * Session-scoped monotonically increasing sequence (§8.3). Stamped on
     * outbound sequenced messages (job events and results) as the
     * envelope's `event_seq`.
     */
    private int $eventSeq = 0;

    public function __construct(
        public readonly Transport $transport,
        public readonly bool $isClient = false,
    ) {
    }

    /** Allocate the next session-scoped `event_seq` value (§8.3). */
    public function nextEventSeq(): int
    {
        return ++$this->eventSeq;
    }

    /** The most recently allocated `event_seq`, 0 if none yet. */
    public function currentEventSeq(): int
    {
        return $this->eventSeq;
    }
}
