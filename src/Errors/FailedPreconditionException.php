<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — pre-condition unmet (e.g. job not in cancellable state). */
final class FailedPreconditionException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::FailedPrecondition;
    }
}
