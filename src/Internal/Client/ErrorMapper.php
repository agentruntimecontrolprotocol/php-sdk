<?php

declare(strict_types=1);

namespace Arcp\Internal\Client;

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

/**
 * Maps a wire-level {@see ErrorPayload} onto the SDK's typed exception
 * hierarchy (ARCP v1.1 §12). Pulled out of {@see \Arcp\Client\ARCPClient}
 * so the client stays focused on its public command surface.
 *
 * @internal
 */
final class ErrorMapper
{
    public function raise(ErrorPayload $err): ARCPException
    {
        $canonical = $err->canonical();
        return match ($canonical) {
            ErrorCode::PermissionDenied => $this->permissionDenied($err),
            ErrorCode::LeaseSubsetViolation => $this->leaseSubsetViolation($err),
            ErrorCode::JobNotFound => new JobNotFoundException($err->message),
            ErrorCode::DuplicateKey => new DuplicateKeyException($err->message),
            ErrorCode::AgentNotAvailable => $this->agentNotAvailable($err),
            ErrorCode::AgentVersionNotAvailable => $this->agentVersionNotAvailable($err),
            ErrorCode::Cancelled => new CancelledException($err->message),
            ErrorCode::Timeout => new TimeoutException($err->message),
            ErrorCode::ResumeWindowExpired => new ResumeWindowExpiredException($err->message),
            ErrorCode::HeartbeatLost => $this->heartbeatLost($err),
            ErrorCode::LeaseExpired => $this->leaseExpired($err),
            ErrorCode::BudgetExhausted => $this->budgetExhausted($err),
            ErrorCode::InvalidRequest => new InvalidRequestException($err->message),
            ErrorCode::Unauthenticated => new UnauthenticatedException($err->message),
            ErrorCode::InternalError => new InternalErrorException($err->message),
            // §12: unrecognized / namespaced extension codes degrade to the
            // INTERNAL_ERROR fallback, preserving the raw code in details.
            default => new InternalErrorException(
                $err->message,
                ['raw_code' => $err->code],
                $err->retryable,
            ),
        };
    }

    private function permissionDenied(ErrorPayload $err): PermissionDeniedException
    {
        $perm = $err->details['permission'] ?? '?';
        $res  = $err->details['resource'] ?? '?';
        return new PermissionDeniedException(
            \is_string($perm) ? $perm : '?',
            \is_string($res) ? $res : '?',
            $err->message,
        );
    }

    private function budgetExhausted(ErrorPayload $err): BudgetExhaustedException
    {
        $currency = $err->details['currency'] ?? '?';
        $remaining = $err->details['remaining'] ?? 0;
        return new BudgetExhaustedException(
            \is_string($currency) ? $currency : '?',
            \is_int($remaining) || \is_float($remaining) || \is_string($remaining)
                ? $remaining
                : 0,
            $err->message,
        );
    }

    private function leaseSubsetViolation(ErrorPayload $err): LeaseSubsetViolationException
    {
        $parent = $err->details['parent_lease_id'] ?? '?';
        $child = $err->details['child_lease_id'] ?? '?';
        $field = $err->details['field'] ?? '?';
        return new LeaseSubsetViolationException(
            \is_string($parent) ? $parent : '?',
            \is_string($child) ? $child : '?',
            \is_string($field) ? $field : '?',
            $err->message,
        );
    }

    private function leaseExpired(ErrorPayload $err): LeaseExpiredException
    {
        $leaseId = $err->details['lease_id'] ?? null;
        $expiredAt = $err->details['expired_at'] ?? null;
        return new LeaseExpiredException(
            new LeaseId(\is_string($leaseId) && $leaseId !== '' ? $leaseId : 'unknown'),
            self::parseTimestamp(\is_string($expiredAt) ? $expiredAt : null),
        );
    }

    private function heartbeatLost(ErrorPayload $err): HeartbeatLostException
    {
        $jobId = $err->details['job_id'] ?? null;
        $missed = $err->details['missed_count'] ?? 0;
        return new HeartbeatLostException(
            new JobId(\is_string($jobId) && $jobId !== '' ? $jobId : 'unknown'),
            \is_int($missed) ? $missed : 0,
        );
    }

    private static function parseTimestamp(?string $value): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new \DateTimeImmutable('@0');
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return new \DateTimeImmutable('@0');
        }
    }

    private function agentNotAvailable(ErrorPayload $err): AgentNotAvailableException
    {
        $agent = $err->details['agent'] ?? '?';
        return new AgentNotAvailableException(
            \is_string($agent) ? $agent : '?',
            $err->message,
        );
    }

    private function agentVersionNotAvailable(ErrorPayload $err): AgentVersionNotAvailableException
    {
        $agent = $err->details['agent'] ?? '?';
        $version = $err->details['version'] ?? '?';
        return new AgentVersionNotAvailableException(
            \is_string($agent) ? $agent : '?',
            \is_string($version) ? $version : '?',
            $err->message,
        );
    }
}
