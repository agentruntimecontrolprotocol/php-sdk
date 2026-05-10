<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — transient unavailability; retry MAY succeed. */
final class UnavailableException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::Unavailable;
    }
}
