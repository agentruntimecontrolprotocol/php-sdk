<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §14 — multi-agent handoff. Deferred to v0.2; runtime nacks. */
final readonly class AgentHandoff extends MessageType
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload = [])
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'agent.handoff';
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
