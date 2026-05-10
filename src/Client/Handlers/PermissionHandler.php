<?php

declare(strict_types=1);

namespace Arcp\Client\Handlers;

use Arcp\Messages\Permissions\PermissionDeny;
use Arcp\Messages\Permissions\PermissionGrant;
use Arcp\Messages\Permissions\PermissionRequest;

/**
 * Application policy for `permission.request` envelopes (RFC §15.4).
 * Returns either a {@see PermissionGrant} or {@see PermissionDeny}.
 */
interface PermissionHandler
{
    public function onPermissionRequest(PermissionRequest $req): PermissionGrant|PermissionDeny;
}
