<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — internal runtime error; prefer a more specific code when available. */
final class InternalException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::Internal;
    }
}
