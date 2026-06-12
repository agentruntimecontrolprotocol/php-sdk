<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** ARCP v1.1 §12 — `idempotency_key` reuse with conflicting parameters. */
final class DuplicateKeyException extends ARCPException
{
    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::DuplicateKey;
    }
}
