<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §10 — durable checkpoint marker. v0.1: shape only; storage Phase 5. */
final readonly class JobCheckpoint extends MessageType
{
    /** @param array<string, mixed> $state */
    public function __construct(
        public string $checkpointId,
        public array $state = [],
    ) {
        if ($checkpointId === '') {
            throw new InvalidArgumentException('checkpoint_id missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.checkpoint';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['checkpoint_id' => $this->checkpointId];
        if ($this->state !== []) {
            $out['state'] = $this->state;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['checkpoint_id'] ?? throw new InvalidArgumentException('checkpoint_id missing');
        if (!\is_string($id)) {
            throw new InvalidArgumentException('checkpoint_id must be string');
        }
        $state = [];
        if (isset($data['state']) && \is_array($data['state'])) {
            /** @var array<string, mixed> $state */
            $state = $data['state'];
        }
        return new static($id, $state);
    }
}
