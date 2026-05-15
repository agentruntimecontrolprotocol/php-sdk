<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Errors\AbortedException;
use Arcp\Errors\AlreadyExistsException;
use Arcp\Errors\ARCPException;
use Arcp\Errors\BackpressureOverflowException;
use Arcp\Errors\CancelledException;
use Arcp\Errors\DataLossException;
use Arcp\Errors\DeadlineExceededException;
use Arcp\Errors\ErrorCode;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\FailedPreconditionException;
use Arcp\Errors\HeartbeatLostException;
use Arcp\Errors\InternalException;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\LeaseExpiredException;
use Arcp\Errors\LeaseRevokedException;
use Arcp\Errors\NotFoundException;
use Arcp\Errors\OutOfRangeException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Errors\ResourceExhaustedException;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Errors\UnavailableException;
use Arcp\Errors\UnimplementedException;
use Arcp\Errors\UnknownException;
use Arcp\Ids\JobId;
use Arcp\Ids\LeaseId;
use Arcp\Ids\TraceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    public function testEveryCanonicalCodeHasExactlyOneException(): void
    {
        // Per-code mapping: the wire string → typed exception. Any new
        // ErrorCode case added to the enum should land here too.
        $expected = [
            ErrorCode::Cancelled->value          => new CancelledException('x'),
            ErrorCode::InvalidArgument->value    => new InvalidArgumentException('x'),
            ErrorCode::DeadlineExceeded->value   => new DeadlineExceededException('x'),
            ErrorCode::NotFound->value           => new NotFoundException('x'),
            ErrorCode::AlreadyExists->value      => new AlreadyExistsException('x'),
            ErrorCode::PermissionDenied->value   => new PermissionDeniedException('p', 'r'),
            ErrorCode::ResourceExhausted->value  => new ResourceExhaustedException('x'),
            ErrorCode::FailedPrecondition->value => new FailedPreconditionException('x'),
            ErrorCode::Aborted->value            => new AbortedException('x'),
            ErrorCode::OutOfRange->value         => new OutOfRangeException('x'),
            ErrorCode::Unimplemented->value      => new UnimplementedException('§1'),
            ErrorCode::Internal->value           => new InternalException('x'),
            ErrorCode::Unavailable->value        => new UnavailableException('x'),
            ErrorCode::DataLoss->value           => new DataLossException('x'),
            ErrorCode::Unauthenticated->value    => new UnauthenticatedException('x'),
            ErrorCode::HeartbeatLost->value      => new HeartbeatLostException(new JobId('j_x'), 1),
            ErrorCode::LeaseExpired->value       => new LeaseExpiredException(
                new LeaseId('l_x'),
                new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            ),
            ErrorCode::LeaseRevoked->value       => new LeaseRevokedException(new LeaseId('l_x')),
            ErrorCode::BackpressureOverflow->value => new BackpressureOverflowException('x'),
            ErrorCode::Unknown->value            => new UnknownException('weird.code'),
        ];

        foreach ($expected as $expectedCode => $exception) {
            $case = ErrorCode::tryFrom($expectedCode);
            self::assertNotNull($case, 'unknown ErrorCode value: ' . $expectedCode);
            self::assertInstanceOf(ARCPException::class, $exception);
            self::assertSame(
                $expectedCode,
                $exception->code()->value,
                $exception::class . ' should report code ' . $expectedCode,
            );
        }
    }

    public function testPermissionDeniedCarriesContext(): void
    {
        $e = new PermissionDeniedException('payment.refund.create', 'order:ord_4812');
        self::assertSame(ErrorCode::PermissionDenied, $e->code());
        self::assertStringContainsString('payment.refund.create', $e->getMessage());
        self::assertStringContainsString('order:ord_4812', $e->getMessage());
        self::assertSame('payment.refund.create', $e->details['permission']);
        self::assertSame('order:ord_4812', $e->details['resource']);
        self::assertFalse($e->isRetryable());
    }

    public function testLeaseExpiredCarriesId(): void
    {
        $id = new LeaseId('lease_abc');
        $when = new \DateTimeImmutable('2026-05-09T13:00:00Z');
        $e = new LeaseExpiredException($id, $when);
        self::assertSame($id, $e->leaseId);
        self::assertSame($when, $e->expiredAt);
        self::assertSame(ErrorCode::LeaseExpired, $e->code());
    }

    public function testLeaseRevokedCarriesReason(): void
    {
        $id = new LeaseId('lease_xyz');
        $e = new LeaseRevokedException($id, 'policy_violation');
        self::assertSame('policy_violation', $e->reason);
        self::assertStringContainsString('policy_violation', $e->getMessage());
    }

    public function testHeartbeatLostCarriesJobAndCount(): void
    {
        $id = new JobId('job_x');
        $e = new HeartbeatLostException($id, 3);
        self::assertSame($id, $e->jobId);
        self::assertSame(3, $e->missedCount);
    }

    public function testUnimplementedCarriesSection(): void
    {
        $e = new UnimplementedException('§14', 'agent.delegate');
        self::assertSame('§14', $e->section);
        self::assertStringContainsString('agent.delegate', $e->getMessage());
        self::assertFalse($e->isRetryable());
    }

    /** @return iterable<string, array{ErrorCode, bool}> */
    public static function retryability(): iterable
    {
        yield 'cancelled not retryable'        => [ErrorCode::Cancelled, false];
        yield 'invalid argument not retryable' => [ErrorCode::InvalidArgument, false];
        yield 'permission denied not retryable' => [ErrorCode::PermissionDenied, false];
        yield 'unimplemented not retryable'    => [ErrorCode::Unimplemented, false];
        yield 'unauthenticated not retryable'  => [ErrorCode::Unauthenticated, false];
        yield 'data loss not retryable'        => [ErrorCode::DataLoss, false];

        yield 'resource exhausted retryable'   => [ErrorCode::ResourceExhausted, true];
        yield 'unavailable retryable'          => [ErrorCode::Unavailable, true];
        yield 'deadline exceeded retryable'    => [ErrorCode::DeadlineExceeded, true];
        yield 'aborted retryable'              => [ErrorCode::Aborted, true];
        yield 'internal retryable'             => [ErrorCode::Internal, true];
    }

    #[DataProvider('retryability')]
    public function testDefaultRetryableMatchesRfc(ErrorCode $code, bool $retryable): void
    {
        self::assertSame($retryable, $code->defaultRetryable());
    }

    public function testErrorPayloadRoundTrip(): void
    {
        $payload = new ErrorPayload(
            code: ErrorCode::ResourceExhausted->value,
            message: 'Upstream rate limit exceeded',
            retryable: true,
            details: ['retry_after_seconds' => 30],
        );
        $arr = $payload->toArray();
        self::assertSame('RESOURCE_EXHAUSTED', $arr['code']);
        self::assertSame('Upstream rate limit exceeded', $arr['message']);
        self::assertSame(true, $arr['retryable'] ?? null);
        $details = $arr['details'] ?? [];
        self::assertSame(30, $details['retry_after_seconds']);

        $back = ErrorPayload::fromArray($arr);
        self::assertSame($payload->code, $back->code);
        self::assertSame($payload->message, $back->message);
        self::assertSame($payload->retryable, $back->retryable);
        self::assertSame($payload->details, $back->details);
    }

    public function testErrorPayloadFromException(): void
    {
        $e = new PermissionDeniedException('p', 'r');
        $payload = ErrorPayload::fromException($e);
        self::assertSame('PERMISSION_DENIED', $payload->code);
        self::assertFalse($payload->retryable);
    }

    public function testErrorPayloadCodeAliasMapsToCanonical(): void
    {
        $payload = new ErrorPayload('RATE_LIMITED', 'limited');
        self::assertSame(ErrorCode::ResourceExhausted, $payload->canonical());
    }

    public function testErrorPayloadNamespacedCanonicalIsNull(): void
    {
        $payload = new ErrorPayload('arcpx.acme.QUOTA_EXCEEDED', 'oops');
        self::assertNull($payload->canonical());
        self::assertTrue($payload->isNamespaced());
    }

    public function testErrorPayloadRequiresNonEmptyCodeAndMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ErrorPayload('', 'msg');
    }

    public function testErrorPayloadRejectsEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ErrorPayload('OK', '');
    }

    public function testErrorPayloadCauseChainAndTraceRoundTrip(): void
    {
        $cause = new ErrorPayload('INTERNAL', 'inner failure');
        $payload = new ErrorPayload(
            code: ErrorCode::Internal->value,
            message: 'outer',
            details: ['k' => 'v'],
            cause: $cause,
            traceId: new TraceId('trace_x'),
        );
        $arr = $payload->toArray();
        self::assertSame('INTERNAL', $arr['code']);
        self::assertSame('trace_x', $arr['trace_id'] ?? null);
        $causeArr = $arr['cause'] ?? null;
        self::assertIsArray($causeArr);
        self::assertSame('INTERNAL', $causeArr['code']);

        $back = ErrorPayload::fromArray($arr);
        self::assertNotNull($back->cause);
        self::assertSame('inner failure', $back->cause->message);
        self::assertNotNull($back->traceId);
        self::assertSame('trace_x', (string) $back->traceId);
    }

    public function testErrorPayloadFromArrayRequiresCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ErrorPayload::fromArray(['message' => 'no code']);
    }

    public function testErrorPayloadFromArrayRequiresMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ErrorPayload::fromArray(['code' => 'OK']);
    }

    public function testErrorPayloadFromArrayRejectsNonStringCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ErrorPayload::fromArray(['code' => 42, 'message' => 'm']);
    }

    public function testErrorPayloadFromArrayRejectsNonBoolRetryable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ErrorPayload::fromArray(['code' => 'OK', 'message' => 'm', 'retryable' => 'yes']);
    }

    public function testErrorPayloadFromArrayRejectsNonObjectDetails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ErrorPayload::fromArray(['code' => 'OK', 'message' => 'm', 'details' => 'not-an-object']);
    }

    public function testErrorPayloadFromArrayRejectsNonObjectCause(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ErrorPayload::fromArray(['code' => 'OK', 'message' => 'm', 'cause' => 'not-an-object']);
    }
}
