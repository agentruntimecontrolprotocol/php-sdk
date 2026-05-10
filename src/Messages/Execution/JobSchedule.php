<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §10.6 — deferred to v0.2; runtime nacks with UNIMPLEMENTED. */
final readonly class JobSchedule extends MessageType
{
    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $when
     */
    public function __construct(public array $job = [], public array $when = [])
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.schedule';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['job' => $this->job, 'when' => $this->when];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $job = [];
        if (isset($data['job']) && \is_array($data['job'])) {
            /** @var array<string, mixed> $job */
            $job = $data['job'];
        }
        $when = [];
        if (isset($data['when']) && \is_array($data['when'])) {
            /** @var array<string, mixed> $when */
            $when = $data['when'];
        }
        return new static($job, $when);
    }
}
