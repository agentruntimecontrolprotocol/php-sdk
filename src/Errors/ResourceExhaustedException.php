<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — quota or rate limit hit. `RATE_LIMITED` is an alias. */
final class ResourceExhaustedException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::ResourceExhausted;
    }
}
