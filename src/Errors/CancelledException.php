<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §10.4 — operation cancelled by caller, runtime, or policy. */
final class CancelledException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::Cancelled;
    }
}
