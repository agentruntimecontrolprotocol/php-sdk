<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — operation timed out before completion. */
final class DeadlineExceededException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::DeadlineExceeded;
    }
}
