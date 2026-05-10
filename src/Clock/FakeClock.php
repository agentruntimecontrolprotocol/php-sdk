<?php

declare(strict_types=1);

namespace Arcp\Clock;

/**
 * Test clock with manual advancement. Heartbeat watchdogs, lease expiry,
 * and retention sweeps must use the injected clock so they can be driven
 * deterministically by tests.
 */
final class FakeClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $start = null)
    {
        $this->now = $start ?? new \DateTimeImmutable('2026-05-09T12:00:00Z');
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int|float $seconds): void
    {
        $micros = (int) round((float) $seconds * 1_000_000.0);
        $this->now = $this->now->modify(\sprintf('+%d microseconds', $micros));
    }

    public function setNow(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
