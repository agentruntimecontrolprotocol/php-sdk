<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — argument out of valid range (subset of `INVALID_ARGUMENT`). */
final class OutOfRangeException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::OutOfRange;
    }
}
