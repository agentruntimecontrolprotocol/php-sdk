<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §6.2 — terminal workflow event. v0.2 will define payload schema. */
final readonly class WorkflowComplete extends MessageType
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload = [])
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'workflow.complete';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->payload;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self($data);
    }
}
