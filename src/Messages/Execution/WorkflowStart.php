<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §6.2 — workflow primitive deferred to v0.2; nacked with UNIMPLEMENTED. */
final readonly class WorkflowStart extends MessageType
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload = [])
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'workflow.start';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->payload;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new static($data);
    }
}
