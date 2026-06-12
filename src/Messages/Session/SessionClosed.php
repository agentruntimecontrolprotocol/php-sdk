<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;

/**
 * ARCP v1.1 §6.7 — graceful-close acknowledgement (`session.closed`).
 * The runtime sends it in response to `session.close` before tearing
 * the transport down; in-flight jobs are not affected and remain
 * resumable within the resume window.
 */
final readonly class SessionClosed extends MessageType
{
    public function __construct(public ?string $reason = null)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.closed';
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
        return new self($reason);
    }
}
