<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Envelope\Priority;
use PHPUnit\Framework\TestCase;

final class PriorityTest extends TestCase
{
    public function testDefaultIsNormal(): void
    {
        self::assertSame(Priority::Normal, Priority::default());
    }

    public function testWireValues(): void
    {
        self::assertSame('low', Priority::Low->value);
        self::assertSame('normal', Priority::Normal->value);
        self::assertSame('high', Priority::High->value);
        self::assertSame('critical', Priority::Critical->value);
    }

    public function testWeightOrdersLowestToHighest(): void
    {
        self::assertLessThan(Priority::Normal->weight(), Priority::Low->weight());
        self::assertLessThan(Priority::High->weight(), Priority::Normal->weight());
        self::assertLessThan(Priority::Critical->weight(), Priority::High->weight());
    }

    public function testTryFromUnknownReturnsNull(): void
    {
        self::assertNull(Priority::tryFrom('urgent'));
    }
}
