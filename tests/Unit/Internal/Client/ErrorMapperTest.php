<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Internal\Client;

use Arcp\Errors\ErrorPayload;
use Arcp\Errors\HeartbeatLostException;
use Arcp\Errors\LeaseExpiredException;
use Arcp\Errors\LeaseRevokedException;
use Arcp\Internal\Client\ErrorMapper;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    public function testLeaseExpiredMapsToTypedExceptionAndIsNotRetryable(): void
    {
        $mapper = new ErrorMapper();
        $payload = new ErrorPayload('LEASE_EXPIRED', 'lease gone', details: [
            'lease_id' => 'lease_abc',
            'expired_at' => '2026-01-01T00:00:00Z',
        ]);

        $exception = $mapper->raise($payload);

        self::assertInstanceOf(LeaseExpiredException::class, $exception);
        self::assertFalse($exception->isRetryable());
        self::assertSame('lease_abc', (string) $exception->leaseId);
    }

    public function testLeaseRevokedMapsToTypedException(): void
    {
        $mapper = new ErrorMapper();
        $payload = new ErrorPayload('LEASE_REVOKED', 'revoked', details: [
            'lease_id' => 'lease_xyz',
            'reason' => 'policy',
        ]);

        $exception = $mapper->raise($payload);

        self::assertInstanceOf(LeaseRevokedException::class, $exception);
        self::assertSame('policy', $exception->reason);
    }

    public function testHeartbeatLostMapsToTypedException(): void
    {
        $mapper = new ErrorMapper();
        $payload = new ErrorPayload('HEARTBEAT_LOST', 'missed beats', details: [
            'job_id' => 'job_1',
            'missed_count' => 3,
        ]);

        $exception = $mapper->raise($payload);

        self::assertInstanceOf(HeartbeatLostException::class, $exception);
        self::assertSame(3, $exception->missedCount);
    }

    public function testSparseDetailsDoNotThrow(): void
    {
        $mapper = new ErrorMapper();
        $exception = $mapper->raise(new ErrorPayload('LEASE_EXPIRED', 'lease gone'));
        self::assertInstanceOf(LeaseExpiredException::class, $exception);
    }
}
