<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2 — entity creation conflicted with existing entity. */
final class AlreadyExistsException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::AlreadyExists;
    }
}
