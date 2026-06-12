<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** ARCP v1.1 §12 — malformed envelope or schema violation. */
final class InvalidRequestException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::InvalidRequest;
    }
}
