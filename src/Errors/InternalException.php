<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — internal runtime error. Avoid in favor of a specific code. */
final class InternalException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::Internal;
    }
}
