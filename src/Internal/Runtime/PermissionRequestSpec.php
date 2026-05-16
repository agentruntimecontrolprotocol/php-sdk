<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

/**
 * Parameter object for {@see \Arcp\Runtime\JobContext::requestPermission()}
 * carrying the request details through the internal helpers.
 *
 * @internal
 */
final readonly class PermissionRequestSpec
{
    public function __construct(
        public string $permission,
        public string $resource,
        public string $operation,
        public string $reason,
        public int $requestedLeaseSeconds,
    ) {
    }
}
