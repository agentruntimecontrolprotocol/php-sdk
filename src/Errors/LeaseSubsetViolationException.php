<?php

declare(strict_types=1);

namespace Arcp\Errors;

final class LeaseSubsetViolationException extends ARCPException
{
    public function __construct(
        string $parentLeaseId,
        string $childLeaseId,
        string $field,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : \sprintf('child lease expands %s beyond parent lease', $field),
            [
                'parent_lease_id' => $parentLeaseId,
                'child_lease_id' => $childLeaseId,
                'field' => $field,
            ],
            false,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::LeaseSubsetViolation;
    }
}
