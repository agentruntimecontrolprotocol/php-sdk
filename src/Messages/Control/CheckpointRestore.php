<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §6.2 — placeholder; checkpoint restore is deferred to v0.2. */
final readonly class CheckpointRestore extends MessageType
{
    public function __construct(public string $checkpointId)
    {
        if ($checkpointId === '') {
            throw new InvalidArgumentException('checkpoint_id missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'checkpoint.restore';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['checkpoint_id' => $this->checkpointId];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['checkpoint_id'] ?? throw new InvalidArgumentException('checkpoint_id missing');
        if (!\is_string($id)) {
            throw new InvalidArgumentException('checkpoint_id must be string');
        }
        return new self($id);
    }
}
