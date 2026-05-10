<?php

declare(strict_types=1);

namespace Arcp\Errors;

/**
 * RFC §15, §18.2 — caller lacks the required permission or lease.
 *
 * `permission` is the dotted-name of the required capability
 * (e.g. `payment.refund.create`), `resource` is the resource the operation
 * was attempted against. Both are surfaced on the wire under `details`.
 */
final class PermissionDeniedException extends ARCPException
{
    public function __construct(
        public readonly string $permission,
        public readonly string $resource,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : \sprintf('permission denied: %s on %s', $permission, $resource),
            ['permission' => $permission, 'resource' => $resource],
            null,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::PermissionDenied;
    }
}
