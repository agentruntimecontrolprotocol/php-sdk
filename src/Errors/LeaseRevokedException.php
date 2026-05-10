<?php

declare(strict_types=1);

namespace Arcp\Errors;

use Arcp\Ids\LeaseId;

/** RFC §15.5, §18.2 — operation attempted with a revoked lease. */
final class LeaseRevokedException extends ARCPException
{
    public function __construct(
        public readonly LeaseId $leaseId,
        public readonly string $reason = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('lease %s revoked%s', $leaseId, $reason !== '' ? ': ' . $reason : ''),
            ['lease_id' => (string) $leaseId, 'reason' => $reason],
            false,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::LeaseRevoked;
    }
}
