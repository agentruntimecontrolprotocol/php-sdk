<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Errors;

use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorCode;
use Arcp\Errors\TransportClosedException;
use PHPUnit\Framework\TestCase;

final class TransportClosedExceptionTest extends TestCase
{
    public function testCodeAndRetryable(): void
    {
        $e = new TransportClosedException('socket gone');
        self::assertSame(ErrorCode::Unavailable, $e->code());
        self::assertTrue($e->isRetryable());
        self::assertInstanceOf(ARCPException::class, $e);
        self::assertSame('socket gone', $e->getMessage());
    }

    public function testRetryableOverrideHonored(): void
    {
        $e = new TransportClosedException('socket gone', retryable: false);
        self::assertFalse($e->isRetryable());
    }
}
