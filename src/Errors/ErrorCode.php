<?php

declare(strict_types=1);

namespace Arcp\Errors;

/**
 * Canonical ARCP error codes (RFC §18.2).
 *
 * Implementations MUST use these codes when applicable; deployment-specific
 * codes MUST be namespaced (e.g. `arcpx.acme.QUOTA_EXCEEDED`) and travel
 * over the wire as their literal string. The {@see ErrorCode::tryFromWire()}
 * factory accepts arbitrary strings so peers can carry namespaced codes
 * even though the enum only covers the canonical set.
 */
enum ErrorCode: string
{
    case Ok = 'OK';
    case Cancelled = 'CANCELLED';
    case Unknown = 'UNKNOWN';
    case InvalidArgument = 'INVALID_ARGUMENT';
    case DeadlineExceeded = 'DEADLINE_EXCEEDED';
    case NotFound = 'NOT_FOUND';
    case AlreadyExists = 'ALREADY_EXISTS';
    case PermissionDenied = 'PERMISSION_DENIED';
    case ResourceExhausted = 'RESOURCE_EXHAUSTED';
    case RateLimited = 'RATE_LIMITED';
    case FailedPrecondition = 'FAILED_PRECONDITION';
    case Aborted = 'ABORTED';
    case OutOfRange = 'OUT_OF_RANGE';
    case Unimplemented = 'UNIMPLEMENTED';
    case Internal = 'INTERNAL';
    case Unavailable = 'UNAVAILABLE';
    case DataLoss = 'DATA_LOSS';
    case Unauthenticated = 'UNAUTHENTICATED';
    case HeartbeatLost = 'HEARTBEAT_LOST';
    case LeaseExpired = 'LEASE_EXPIRED';
    case LeaseRevoked = 'LEASE_REVOKED';
    case BackpressureOverflow = 'BACKPRESSURE_OVERFLOW';

    /**
     * Whether this code is retryable by default per RFC §18.3. Senders MAY
     * still override the default by setting `retryable` explicitly on the
     * error envelope.
     */
    public function defaultRetryable(): bool
    {
        return match ($this) {
            self::ResourceExhausted, self::RateLimited, self::Unavailable,
            self::DeadlineExceeded, self::Internal, self::Aborted => true,
            default => false,
        };
    }
}
