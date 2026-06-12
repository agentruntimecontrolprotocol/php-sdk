<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** ARCP v1.1 §12 — referenced `job_id` does not exist or is not visible. */
final class JobNotFoundException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::JobNotFound;
    }
}
