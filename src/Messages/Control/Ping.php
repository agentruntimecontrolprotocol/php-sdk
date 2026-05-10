<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;

/** RFC §6.2 — keepalive probe. */
final readonly class Ping extends MessageType
{
    public function __construct(public ?string $nonce = null)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'ping';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->nonce !== null ? ['nonce' => $this->nonce] : [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $nonce = null;
        if (isset($data['nonce']) && \is_string($data['nonce'])) {
            $nonce = $data['nonce'];
        }
        return new static($nonce);
    }
}
