<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** ARCP v1.1 §12 — job or operation exceeded its allowed runtime. */
final class TimeoutException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::Timeout;
    }
}
