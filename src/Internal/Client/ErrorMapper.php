<?php

declare(strict_types=1);

namespace Arcp\Internal\Client;

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
use Arcp\Errors\InternalException;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\NotFoundException;
use Arcp\Errors\OutOfRangeException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Errors\ResourceExhaustedException;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Errors\UnavailableException;
use Arcp\Errors\UnimplementedException;
use Arcp\Errors\UnknownException;

/**
 * Maps a wire-level {@see ErrorPayload} onto the SDK's typed exception
 * hierarchy. Pulled out of {@see \Arcp\Client\ARCPClient} so the client
 * stays focused on its public command surface.
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
            ErrorCode::Unimplemented => new UnimplementedException('?', $err->message),
            ErrorCode::DeadlineExceeded => new DeadlineExceededException($err->message),
            ErrorCode::Cancelled => new CancelledException($err->message),
            ErrorCode::NotFound => new NotFoundException($err->message),
            ErrorCode::AlreadyExists => new AlreadyExistsException($err->message),
            ErrorCode::ResourceExhausted => new ResourceExhaustedException($err->message),
            ErrorCode::FailedPrecondition => new FailedPreconditionException($err->message),
            ErrorCode::InvalidArgument => new InvalidArgumentException($err->message),
            ErrorCode::Internal => new InternalException($err->message),
            ErrorCode::Unavailable => new UnavailableException($err->message),
            ErrorCode::DataLoss => new DataLossException($err->message),
            ErrorCode::Unauthenticated => new UnauthenticatedException($err->message),
            ErrorCode::Aborted => new AbortedException($err->message),
            ErrorCode::OutOfRange => new OutOfRangeException($err->message),
            ErrorCode::BackpressureOverflow => new BackpressureOverflowException($err->message),
            default => new UnknownException($err->code, $err->message),
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
}
