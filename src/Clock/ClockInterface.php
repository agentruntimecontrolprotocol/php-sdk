<?php

declare(strict_types=1);

namespace Arcp\Clock;

/**
 * Abstraction over wall-clock time so heartbeats, lease expiry, and
 * artifact retention can be exercised deterministically in tests.
 *
 * RFC §6.1 (envelope `timestamp`), §10.3 (heartbeat deadlines),
 * §15.5 (lease `expires_at`), §16.3 (retention) all need a clock; we
 * funnel every call through this interface.
 */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
