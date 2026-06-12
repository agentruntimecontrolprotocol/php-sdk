<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** RFC §10.3 — periodic liveness signal. */
final readonly class JobHeartbeat extends MessageType
{
    public function __construct(
        public int $sequence,
        public int $deadlineMs,
        public string $state = 'running',
    ) {
        if ($sequence < 0) {
            throw new InvalidRequestException('sequence must be ≥ 0');
        }
        if ($deadlineMs <= 0) {
            throw new InvalidRequestException('deadline_ms must be > 0');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.heartbeat';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'deadline_ms' => $this->deadlineMs,
            'state' => $this->state,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $seq = $data['sequence'] ?? throw new InvalidRequestException('sequence missing');
        $deadline = $data['deadline_ms']
            ?? throw new InvalidRequestException('deadline_ms missing');
        if (!\is_int($seq) || !\is_int($deadline)) {
            throw new InvalidRequestException('sequence/deadline_ms must be ints');
        }
        $state = 'running';
        if (isset($data['state']) && \is_string($data['state'])) {
            $state = $data['state'];
        }
        return new self($seq, $deadline, $state);
    }
}
