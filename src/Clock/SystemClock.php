<?php

declare(strict_types=1);

namespace Arcp\Clock;

/**
 * Default {@see ClockInterface} backed by the operating-system clock.
 */
final class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
