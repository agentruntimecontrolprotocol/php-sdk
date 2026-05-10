<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Clock\FakeClock;
use Arcp\Clock\SystemClock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function testSystemClockReturnsRecentUtcTime(): void
    {
        $before = time();
        $clock = new SystemClock();
        $now = $clock->now();
        $after = time();
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual($after, $now->getTimestamp());
        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    public function testFakeClockHonorsAdvance(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $clock->advance(5);
        self::assertSame('2026-01-01T00:00:05+00:00', $clock->now()->format(\DATE_RFC3339));

        $clock->advance(0.5);
        self::assertSame(500_000, (int) $clock->now()->format('u'));
    }

    public function testFakeClockSetNow(): void
    {
        $clock = new FakeClock();
        $when = new \DateTimeImmutable('2030-12-31T23:59:59Z');
        $clock->setNow($when);
        self::assertSame($when, $clock->now());
    }

    public function testFakeClockDefaultStart(): void
    {
        $clock = new FakeClock();
        self::assertSame('2026-05-09T12:00:00+00:00', $clock->now()->format(\DATE_RFC3339));
    }
}
