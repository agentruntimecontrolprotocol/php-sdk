<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §11.2, §13.4, §18.2 — subscription or stream dropped due to overflow. */
final class BackpressureOverflowException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::BackpressureOverflow;
    }
}
