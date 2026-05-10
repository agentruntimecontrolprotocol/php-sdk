<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §6.3 — terminal success of a direct tool invocation. */
final readonly class ToolResult extends MessageType
{
    public function __construct(
        public mixed $value = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'tool.result';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->value !== null ? ['value' => $this->value] : [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $value = $data['value'] ?? null;
        return new static($value);
    }
}
