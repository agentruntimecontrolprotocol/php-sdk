<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — caller passed a malformed or invalid argument. */
final class InvalidArgumentException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::InvalidArgument;
    }
}
