<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §8, §18.2 — missing or invalid credentials. */
final class UnauthenticatedException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::Unauthenticated;
    }
}
