<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — concurrency conflict or hard termination. */
final class AbortedException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::Aborted;
    }
}
