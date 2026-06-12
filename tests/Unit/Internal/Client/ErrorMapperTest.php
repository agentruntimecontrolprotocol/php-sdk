<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Internal\Client;

use Arcp\Errors\ErrorPayload;
use Arcp\Errors\HeartbeatLostException;
use Arcp\Errors\InternalErrorException;
use Arcp\Errors\JobNotFoundException;
use Arcp\Errors\LeaseExpiredException;
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

    public function testJobNotFoundMapsToTypedException(): void
    {
        $mapper = new ErrorMapper();
        $payload = new ErrorPayload('JOB_NOT_FOUND', 'no such job');

        $exception = $mapper->raise($payload);

        self::assertInstanceOf(JobNotFoundException::class, $exception);
        self::assertFalse($exception->isRetryable());
    }

    public function testUnknownCodeFallsBackToInternalError(): void
    {
        // §12: codes outside the taxonomy degrade to INTERNAL_ERROR while
        // preserving the raw wire code in details.
        $mapper = new ErrorMapper();
        $exception = $mapper->raise(new ErrorPayload('arcpx.acme.QUOTA', 'quota'));

        self::assertInstanceOf(InternalErrorException::class, $exception);
        self::assertSame('arcpx.acme.QUOTA', $exception->details['raw_code']);
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
