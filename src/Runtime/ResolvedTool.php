<?php

declare(strict_types=1);

namespace Arcp\Runtime;

/** Internal result of resolving a submitted agent/tool reference. */
final readonly class ResolvedTool
{
    public function __construct(
        public string $name,
        public ?string $version,
        public ToolHandler $handler,
    ) {
    }

    public function ref(): string
    {
        return $this->version === null ? $this->name : $this->name . '@' . $this->version;
    }
}
