<?php

declare(strict_types=1);

namespace Arcp\Runtime;

/**
 * Authorization triple checked when consuming a lease (RFC §15.5). Used as
 * the parameter object for {@see LeaseManager::ensureUsable()}.
 */
final readonly class LeaseScope
{
    public function __construct(
        public string $permission,
        public string $resource,
        public string $operation,
    ) {
    }
}
