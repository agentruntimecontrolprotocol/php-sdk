<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §14 — multi-agent delegation. Deferred to v0.2; runtime nacks. */
final readonly class AgentDelegate extends MessageType
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload = [])
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'agent.delegate';
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
