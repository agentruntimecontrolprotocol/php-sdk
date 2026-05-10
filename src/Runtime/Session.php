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

    public function __construct(
        public readonly Transport $transport,
        public readonly bool $isClient = false,
    ) {
    }
}
