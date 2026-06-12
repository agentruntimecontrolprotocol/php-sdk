<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Messages\Session;

use Arcp\Errors\InvalidArgumentException;
use Arcp\Messages\Session\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    public function testHeartbeatIntervalBelowOneSecondIsRejected(): void
    {
        // §6.4: a sub-second interval (which truncates to 0 as an int) must
        // not be silently advertised as 0s.
        $this->expectException(InvalidArgumentException::class);
        new Capabilities(heartbeatIntervalSeconds: 0);
    }

    public function testValidHeartbeatIntervalRoundTrips(): void
    {
        $caps = new Capabilities(heartbeatIntervalSeconds: 30);
        self::assertSame(30, $caps->toArray()['heartbeat_interval_seconds']);
    }
}
