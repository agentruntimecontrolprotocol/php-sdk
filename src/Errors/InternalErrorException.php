<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** ARCP v1.1 §12 — unrecoverable runtime fault. Always retryable. */
final class InternalErrorException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::InternalError;
    }
}
