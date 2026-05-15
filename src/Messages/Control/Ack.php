<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;

/** RFC §6.3 — command acknowledged. `correlation_id` on the envelope points at the command. */
final readonly class Ack extends MessageType
{
    public function __construct(public ?string $note = null)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'ack';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->note !== null ? ['note' => $this->note] : [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $note = null;
        if (isset($data['note']) && \is_string($data['note'])) {
            $note = $data['note'];
        }
        return new self($note);
    }
}
