<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §6.3 / §10 — execute a named tool with the given arguments. */
final readonly class ToolInvoke extends MessageType
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $tool,
        public array $arguments = [],
    ) {
        if ($tool === '') {
            throw new InvalidArgumentException('tool name missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'tool.invoke';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['tool' => $this->tool, 'arguments' => $this->arguments];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $tool = $data['tool'] ?? throw new InvalidArgumentException('tool missing');
        if (!\is_string($tool)) {
            throw new InvalidArgumentException('tool must be string');
        }
        $args = [];
        if (isset($data['arguments'])) {
            if (!\is_array($data['arguments'])) {
                throw new InvalidArgumentException('arguments must be object');
            }
            /** @var array<string, mixed> $args */
            $args = $data['arguments'];
        }
        return new self($tool, $args);
    }
}
