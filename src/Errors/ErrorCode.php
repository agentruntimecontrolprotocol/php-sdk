<?php

declare(strict_types=1);

namespace Arcp\Errors;

/**
 * Canonical ARCP error codes (ARCP v1.1 §12).
 *
 * Implementations MUST use these codes when applicable; deployment-specific
 * codes MUST be namespaced (e.g. `arcpx.acme.QUOTA_EXCEEDED`) and travel
 * over the wire as their literal string. Use {@see ErrorCode::tryFrom()} for
 * an exact enum match, or {@see \Arcp\Errors\ErrorPayload::canonical()} which
 * returns null for namespaced/extension codes the enum does not cover.
 */
enum ErrorCode: string
{
    case PermissionDenied = 'PERMISSION_DENIED';
    case LeaseSubsetViolation = 'LEASE_SUBSET_VIOLATION';
    case JobNotFound = 'JOB_NOT_FOUND';
    case DuplicateKey = 'DUPLICATE_KEY';
    case AgentNotAvailable = 'AGENT_NOT_AVAILABLE';
    case AgentVersionNotAvailable = 'AGENT_VERSION_NOT_AVAILABLE';
    case Cancelled = 'CANCELLED';
    case Timeout = 'TIMEOUT';
    case ResumeWindowExpired = 'RESUME_WINDOW_EXPIRED';
    case HeartbeatLost = 'HEARTBEAT_LOST';
    case LeaseExpired = 'LEASE_EXPIRED';
    case BudgetExhausted = 'BUDGET_EXHAUSTED';
    case InvalidRequest = 'INVALID_REQUEST';
    case Unauthenticated = 'UNAUTHENTICATED';
    case InternalError = 'INTERNAL_ERROR';

    /**
     * Whether this code is retryable by default per §12. Senders MAY still
     * override the default by setting `retryable` explicitly on the error
     * payload, except `LEASE_EXPIRED` and `BUDGET_EXHAUSTED` which MUST be
     * `retryable: false` and `INTERNAL_ERROR` which is always retryable.
     */
    public function defaultRetryable(): bool
    {
        return match ($this) {
            self::Timeout, self::HeartbeatLost, self::InternalError => true,
            default => false,
        };
    }
}
