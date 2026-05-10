<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;

/** RFC §9 — graceful close. */
final readonly class SessionClose extends MessageType
{
    public function __construct(public ?string $reason = null)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.close';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->reason !== null ? ['reason' => $this->reason] : [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $reason = null;
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        return new static($reason);
    }
}
