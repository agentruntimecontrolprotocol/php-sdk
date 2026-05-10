<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §10.2 — runtime accepted the job; envelope `job_id` carries the new id. */
final readonly class JobAccepted extends MessageType
{
    public function __construct(public ?string $note = null)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.accepted';
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
        return new static($note);
    }
}
