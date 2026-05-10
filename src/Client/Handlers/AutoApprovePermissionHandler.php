<?php

declare(strict_types=1);

namespace Arcp\Client\Handlers;

use Arcp\Messages\Permissions\PermissionGrant;
use Arcp\Messages\Permissions\PermissionRequest;

/** Test/sample handler that grants every request. */
final class AutoApprovePermissionHandler implements PermissionHandler
{
    public function __construct(public readonly int $leaseSeconds = 300)
    {
    }

    #[\Override]
    public function onPermissionRequest(PermissionRequest $req): PermissionGrant
    {
        return new PermissionGrant(
            $req->permission,
            $req->resource,
            $req->operation,
            $req->requestedLeaseSeconds ?? $this->leaseSeconds,
        );
    }
}
