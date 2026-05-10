<?php

declare(strict_types=1);

namespace Arcp\Errors;

use Arcp\Ids\LeaseId;

/** RFC §15.5, §18.2 — operation attempted with expired lease. */
final class LeaseExpiredException extends ARCPException
{
    public function __construct(
        public readonly LeaseId $leaseId,
        public readonly \DateTimeImmutable $expiredAt,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('lease %s expired at %s', $leaseId, $expiredAt->format(\DATE_RFC3339)),
            ['lease_id' => (string) $leaseId, 'expired_at' => $expiredAt->format(\DATE_RFC3339)],
            false,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::LeaseExpired;
    }
}
