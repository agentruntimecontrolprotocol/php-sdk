<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Errors\AgentNotAvailableException;
use Arcp\Errors\AgentVersionNotAvailableException;
use Arcp\Errors\ARCPException;
use Arcp\Errors\BudgetExhaustedException;
use Arcp\Errors\CancelledException;
use Arcp\Errors\DuplicateKeyException;
use Arcp\Errors\ErrorCode;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\HeartbeatLostException;
use Arcp\Errors\InternalErrorException;
use Arcp\Errors\InvalidRequestException;
use Arcp\Errors\JobNotFoundException;
use Arcp\Errors\LeaseExpiredException;
use Arcp\Errors\LeaseSubsetViolationException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Errors\ResumeWindowExpiredException;
use Arcp\Errors\TimeoutException;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Ids\JobId;
use Arcp\Ids\LeaseId;
use Arcp\Ids\TraceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    public function testEveryCanonicalCodeHasExactlyOneException(): void
    {
        // Per-code mapping: the §12 wire string → typed exception. Any new
        // ErrorCode case added to the enum should land here too.
        $expected = [
            ErrorCode::PermissionDenied->value => new PermissionDeniedException('p', 'r'),
            ErrorCode::LeaseSubsetViolation->value
                => new LeaseSubsetViolationException('l_p', 'l_c', 'fs.read'),
            ErrorCode::JobNotFound->value => new JobNotFoundException('x'),
            ErrorCode::DuplicateKey->value => new DuplicateKeyException('x'),
            ErrorCode::AgentNotAvailable->value => new AgentNotAvailableException('planner'),
            ErrorCode::AgentVersionNotAvailable->value
                => new AgentVersionNotAvailableException('planner', '9.9.9'),
            ErrorCode::Cancelled->value => new CancelledException('x'),
            ErrorCode::Timeout->value => new TimeoutException('x'),
            ErrorCode::ResumeWindowExpired->value => new ResumeWindowExpiredException('x'),
            ErrorCode::HeartbeatLost->value => new HeartbeatLostException(new JobId('j_x'), 1),
            ErrorCode::LeaseExpired->value => new LeaseExpiredException(
                new LeaseId('l_x'),
                new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            ),
            ErrorCode::BudgetExhausted->value => new BudgetExhaustedException('USD', '0'),
            ErrorCode::InvalidRequest->value => new InvalidRequestException('x'),
            ErrorCode::Unauthenticated->value => new UnauthenticatedException('x'),
            ErrorCode::InternalError->value => new InternalErrorException('x'),
        ];

        self::assertCount(\count(ErrorCode::cases()), $expected);
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

    public function testWireStringsMatchSpecSection12(): void
    {
        $expected = [
            'PERMISSION_DENIED',
            'LEASE_SUBSET_VIOLATION',
            'JOB_NOT_FOUND',
            'DUPLICATE_KEY',
            'AGENT_NOT_AVAILABLE',
            'AGENT_VERSION_NOT_AVAILABLE',
            'CANCELLED',
            'TIMEOUT',
            'RESUME_WINDOW_EXPIRED',
            'HEARTBEAT_LOST',
            'LEASE_EXPIRED',
            'BUDGET_EXHAUSTED',
            'INVALID_REQUEST',
            'UNAUTHENTICATED',
            'INTERNAL_ERROR',
        ];
        self::assertSame(
            $expected,
            array_map(static fn (ErrorCode $c): string => $c->value, ErrorCode::cases()),
        );
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

    public function testHeartbeatLostCarriesJobAndCount(): void
    {
        $id = new JobId('job_x');
        $e = new HeartbeatLostException($id, 3);
        self::assertSame($id, $e->jobId);
        self::assertSame(3, $e->missedCount);
    }

    public function testAgentNotAvailableCarriesAgent(): void
    {
        $e = new AgentNotAvailableException('planner');
        self::assertSame(ErrorCode::AgentNotAvailable, $e->code());
        self::assertSame('planner', $e->details['agent']);
        self::assertStringContainsString('planner', $e->getMessage());
        self::assertFalse($e->isRetryable());
    }

    /** @return iterable<string, array{ErrorCode, bool}> */
    public static function retryability(): iterable
    {
        yield 'permission denied not retryable' => [ErrorCode::PermissionDenied, false];
        yield 'lease subset violation not retryable' => [ErrorCode::LeaseSubsetViolation, false];
        yield 'job not found not retryable' => [ErrorCode::JobNotFound, false];
        yield 'duplicate key not retryable' => [ErrorCode::DuplicateKey, false];
        yield 'agent not available not retryable' => [ErrorCode::AgentNotAvailable, false];
        yield 'agent version not available not retryable'
            => [ErrorCode::AgentVersionNotAvailable, false];
        yield 'cancelled not retryable' => [ErrorCode::Cancelled, false];
        yield 'resume window expired not retryable' => [ErrorCode::ResumeWindowExpired, false];
        // §12: LEASE_EXPIRED and BUDGET_EXHAUSTED MUST be retryable: false.
        yield 'lease expired not retryable' => [ErrorCode::LeaseExpired, false];
        yield 'budget exhausted not retryable' => [ErrorCode::BudgetExhausted, false];
        yield 'invalid request not retryable' => [ErrorCode::InvalidRequest, false];
        yield 'unauthenticated not retryable' => [ErrorCode::Unauthenticated, false];

        yield 'timeout retryable' => [ErrorCode::Timeout, true];
        yield 'heartbeat lost retryable' => [ErrorCode::HeartbeatLost, true];
        // §12: INTERNAL_ERROR is always retryable.
        yield 'internal error retryable' => [ErrorCode::InternalError, true];
    }

    #[DataProvider('retryability')]
    public function testDefaultRetryableMatchesSpec(ErrorCode $code, bool $retryable): void
    {
        self::assertSame($retryable, $code->defaultRetryable());
    }

    public function testErrorPayloadRoundTrip(): void
    {
        $payload = new ErrorPayload(
            code: ErrorCode::Timeout->value,
            message: 'job exceeded max_runtime_sec',
            retryable: true,
            details: ['max_runtime_sec' => 30],
        );
        $arr = $payload->toArray();
        self::assertSame('TIMEOUT', $arr['code']);
        self::assertSame('job exceeded max_runtime_sec', $arr['message']);
        self::assertSame(true, $arr['retryable'] ?? null);
        $details = $arr['details'] ?? [];
        self::assertSame(30, $details['max_runtime_sec']);

        $back = ErrorPayload::fromArray($arr);
        self::assertSame($payload->code, $back->code);
        self::assertSame($payload->message, $back->message);
        self::assertSame($payload->retryable, $back->retryable);
        self::assertSame($payload->details, $back->details);
    }

    public function testRetryableAlwaysEmittedAndDerived(): void
    {
        // §12: every encoded error payload carries a retryable boolean even
        // when not set explicitly.
        $leaseExpired = new ErrorPayload('LEASE_EXPIRED', 'lease ended');
        self::assertArrayHasKey('retryable', $leaseExpired->toArray());
        self::assertFalse($leaseExpired->toArray()['retryable']);

        $budget = new ErrorPayload('BUDGET_EXHAUSTED', 'no funds');
        self::assertFalse($budget->toArray()['retryable']);

        $internal = new ErrorPayload('INTERNAL_ERROR', 'boom');
        self::assertTrue($internal->toArray()['retryable']);

        // An explicit flag still wins over the derived default.
        $forced = new ErrorPayload('INTERNAL_ERROR', 'boom', retryable: false);
        self::assertFalse($forced->toArray()['retryable']);
    }

    public function testErrorPayloadFromException(): void
    {
        $e = new PermissionDeniedException('p', 'r');
        $payload = ErrorPayload::fromException($e);
        self::assertSame('PERMISSION_DENIED', $payload->code);
        self::assertFalse($payload->retryable);
    }

    public function testErrorPayloadNamespacedCanonicalIsNull(): void
    {
        $payload = new ErrorPayload('arcpx.acme.QUOTA_EXCEEDED', 'oops');
        self::assertNull($payload->canonical());
        self::assertTrue($payload->isNamespaced());
    }

    public function testErrorPayloadRequiresNonEmptyCodeAndMessage(): void
    {
        $this->expectException(InvalidRequestException::class);
        new ErrorPayload('', 'msg');
    }

    public function testErrorPayloadRejectsEmptyMessage(): void
    {
        $this->expectException(InvalidRequestException::class);
        new ErrorPayload('CANCELLED', '');
    }

    public function testErrorPayloadCauseChainAndTraceRoundTrip(): void
    {
        $cause = new ErrorPayload('INTERNAL_ERROR', 'inner failure');
        $payload = new ErrorPayload(
            code: ErrorCode::InternalError->value,
            message: 'outer',
            details: ['k' => 'v'],
            cause: $cause,
            traceId: new TraceId('trace_x'),
        );
        $arr = $payload->toArray();
        self::assertSame('INTERNAL_ERROR', $arr['code']);
        self::assertSame('trace_x', $arr['trace_id'] ?? null);
        $causeArr = $arr['cause'] ?? null;
        self::assertIsArray($causeArr);
        self::assertSame('INTERNAL_ERROR', $causeArr['code']);

        $back = ErrorPayload::fromArray($arr);
        self::assertNotNull($back->cause);
        self::assertSame('inner failure', $back->cause->message);
        self::assertNotNull($back->traceId);
        self::assertSame('trace_x', (string) $back->traceId);
    }

    public function testErrorPayloadFromArrayRequiresCode(): void
    {
        $this->expectException(InvalidRequestException::class);
        ErrorPayload::fromArray(['message' => 'no code']);
    }

    public function testErrorPayloadFromArrayRequiresMessage(): void
    {
        $this->expectException(InvalidRequestException::class);
        ErrorPayload::fromArray(['code' => 'CANCELLED']);
    }

    public function testErrorPayloadFromArrayRejectsNonStringCode(): void
    {
        $this->expectException(InvalidRequestException::class);
        ErrorPayload::fromArray(['code' => 42, 'message' => 'm']);
    }

    public function testErrorPayloadFromArrayRejectsNonBoolRetryable(): void
    {
        $this->expectException(InvalidRequestException::class);
        ErrorPayload::fromArray(['code' => 'CANCELLED', 'message' => 'm', 'retryable' => 'yes']);
    }

    public function testErrorPayloadFromArrayRejectsNonObjectDetails(): void
    {
        $this->expectException(InvalidRequestException::class);
        ErrorPayload::fromArray([
            'code' => 'CANCELLED',
            'message' => 'm',
            'details' => 'not-an-object',
        ]);
    }

    public function testErrorPayloadFromArrayRejectsNonObjectCause(): void
    {
        $this->expectException(InvalidRequestException::class);
        ErrorPayload::fromArray(['code' => 'CANCELLED', 'message' => 'm', 'cause' => 'not-an-object']);
    }
}
