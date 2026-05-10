<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — referenced entity does not exist. */
final class NotFoundException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::NotFound;
    }
}
