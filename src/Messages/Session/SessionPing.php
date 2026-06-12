<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** ARCP v1.1 §6.4 — heartbeat probe: `{nonce, sent_at}`. */
final readonly class SessionPing extends MessageType
{
    public function __construct(
        public string $nonce,
        public \DateTimeImmutable $sentAt,
    ) {
        if ($nonce === '') {
            throw new InvalidRequestException('session.ping nonce must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.ping';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'nonce' => $this->nonce,
            'sent_at' => $this->sentAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $nonce = $data['nonce'] ?? throw new InvalidRequestException('session.ping nonce missing');
        if (!\is_string($nonce)) {
            throw new InvalidRequestException('session.ping nonce must be string');
        }
        $sentAt = $data['sent_at']
            ?? throw new InvalidRequestException('session.ping sent_at missing');
        if (!\is_string($sentAt)) {
            throw new InvalidRequestException('session.ping sent_at must be string');
        }
        try {
            return new self($nonce, new \DateTimeImmutable($sentAt));
        } catch (\DateMalformedStringException $e) {
            throw new InvalidRequestException(
                'session.ping sent_at must be RFC 3339',
                ['sent_at' => $sentAt],
                null,
                $e,
            );
        }
    }
}
