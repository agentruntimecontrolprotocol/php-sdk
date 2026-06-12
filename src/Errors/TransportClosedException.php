<?php

declare(strict_types=1);

namespace Arcp\Errors;

/**
 * The underlying ARCP transport (stdio pipe, memory pair, WebSocket
 * frame stream) is closed. Maps to the canonical
 * {@see ErrorCode::HeartbeatLost} (§12: peer detected counterparty
 * disconnection) because the failure is transient — reconnecting with the
 * same identity MAY succeed.
 *
 * Replaces the prior `\RuntimeException('transport closed')` throws so
 * consumers can catch transport-shape errors specifically while existing
 * `catch (\RuntimeException)` blocks keep working (this class still
 * descends from {@see \RuntimeException} via {@see ARCPException}).
 */
final class TransportClosedException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::HeartbeatLost;
    }
}
