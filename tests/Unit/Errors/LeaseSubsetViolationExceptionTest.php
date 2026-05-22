<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Errors;

use Arcp\Errors\ErrorCode;
use Arcp\Errors\LeaseSubsetViolationException;
use PHPUnit\Framework\TestCase;

final class LeaseSubsetViolationExceptionTest extends TestCase
{
    public function testCodeAndDetails(): void
    {
        $e = new LeaseSubsetViolationException('lease_parent', 'lease_child', 'model.use');

        self::assertSame(ErrorCode::LeaseSubsetViolation, $e->code());
        self::assertFalse($e->isRetryable());
        self::assertSame('lease_parent', $e->details['parent_lease_id']);
        self::assertSame('lease_child', $e->details['child_lease_id']);
        self::assertSame('model.use', $e->details['field']);
    }
}
