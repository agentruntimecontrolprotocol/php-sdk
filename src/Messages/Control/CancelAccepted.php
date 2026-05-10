<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;

/** RFC §10.4 — runtime accepted the cancellation request. */
final readonly class CancelAccepted extends MessageType
{
    public function __construct(public ?int $deadlineMs = null)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'cancel.accepted';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->deadlineMs !== null ? ['deadline_ms' => $this->deadlineMs] : [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $deadline = null;
        if (isset($data['deadline_ms']) && \is_int($data['deadline_ms'])) {
            $deadline = $data['deadline_ms'];
        }
        return new static($deadline);
    }
}
