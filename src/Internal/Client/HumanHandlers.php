<?php

declare(strict_types=1);

namespace Arcp\Internal\Client;

use Arcp\Client\Handlers\HumanInputHandler;
use Arcp\Client\Handlers\PermissionHandler;

/**
 * Late-binding bundle of optional interaction handlers consumed by
 * {@see ResponseRouter}.
 *
 * The handlers themselves live on {@see \Arcp\Client\ARCPClient} as
 * publicly mutable properties; callers may swap them between requests,
 * so the router reads through closures rather than capturing a snapshot.
 *
 * @internal
 */
final readonly class HumanHandlers
{
    /**
     * @param \Closure(): ?HumanInputHandler $humanInput
     * @param \Closure(): ?PermissionHandler $permission
     */
    public function __construct(
        private \Closure $humanInput,
        private \Closure $permission,
    ) {
    }

    public function humanInput(): ?HumanInputHandler
    {
        return ($this->humanInput)();
    }

    public function permission(): ?PermissionHandler
    {
        return ($this->permission)();
    }
}
