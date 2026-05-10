<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** RFC §18.2, §19 — unrecoverable data loss or corruption (e.g. retention expired). */
final class DataLossException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::DataLoss;
    }
}
