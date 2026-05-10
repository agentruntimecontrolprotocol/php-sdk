<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §10.2 — terminal: job succeeded. */
final readonly class JobCompleted extends MessageType
{
    public function __construct(public mixed $value = null)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.completed';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->value !== null ? ['value' => $this->value] : [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new static($data['value'] ?? null);
    }
}
