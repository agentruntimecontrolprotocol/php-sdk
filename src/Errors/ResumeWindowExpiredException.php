<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** ARCP v1.1 §12 — resume attempted after the buffer window closed. */
final class ResumeWindowExpiredException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::ResumeWindowExpired;
    }
}
