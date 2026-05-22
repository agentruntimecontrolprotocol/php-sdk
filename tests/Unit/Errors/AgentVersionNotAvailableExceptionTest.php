<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Errors;

use Arcp\Errors\AgentVersionNotAvailableException;
use Arcp\Errors\ErrorCode;
use PHPUnit\Framework\TestCase;

final class AgentVersionNotAvailableExceptionTest extends TestCase
{
    public function testCodeDetailsAndRetryableFlag(): void
    {
        $e = new AgentVersionNotAvailableException('planner', '9.9.9');

        self::assertSame(ErrorCode::AgentVersionNotAvailable, $e->code());
        self::assertSame('planner', $e->details['agent']);
        self::assertSame('9.9.9', $e->details['version']);
        self::assertFalse($e->isRetryable());
    }
}
