<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §10.2 — execution has begun. */
final readonly class JobStarted extends MessageType
{
    public function __construct(public ?\DateTimeImmutable $startedAt = null)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.started';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->startedAt !== null
            ? ['started_at' => $this->startedAt->format(\DateTimeInterface::RFC3339_EXTENDED)]
            : [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $started = null;
        if (isset($data['started_at']) && \is_string($data['started_at'])) {
            $started = new \DateTimeImmutable($data['started_at']);
        }
        return new static($started);
    }
}
