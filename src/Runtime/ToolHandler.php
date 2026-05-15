<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\Cancellation;

/**
 * Application-supplied tool implementation. The runtime invokes this
 * inside a fiber (RFC §10) — it MAY emit progress, request human input,
 * request permissions, or open streams via {@see JobContext}.
 *
 * Returning a value resolves the call as `tool.result` / `job.completed`.
 * Throwing an {@see \Arcp\Errors\ARCPException} resolves as
 * `tool.error` / `job.failed`. Throwing anything else becomes `INTERNAL`.
 */
interface ToolHandler
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function invoke(
        array $arguments,
        JobContext $ctx,
        ?Cancellation $cancellation = null,
    ): mixed;
}
