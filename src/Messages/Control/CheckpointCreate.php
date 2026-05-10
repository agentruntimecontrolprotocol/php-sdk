<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;

/**
 * RFC §6.2 — placeholder for v0.1; checkpoint storage is deferred to
 * v0.2. The runtime nacks any inbound `checkpoint.create` with
 * `UNIMPLEMENTED`; the wire shape exists for forward compatibility.
 */
final readonly class CheckpointCreate extends MessageType
{
    /** @param array<string, mixed> $state */
    public function __construct(public array $state = [])
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'checkpoint.create';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->state !== [] ? ['state' => $this->state] : [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $state = [];
        if (isset($data['state']) && \is_array($data['state'])) {
            /** @var array<string, mixed> $state */
            $state = $data['state'];
        }
        return new static($state);
    }
}
