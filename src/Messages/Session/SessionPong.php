<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** ARCP v1.1 §6.4 — heartbeat reply: `{ping_nonce, received_at}`. */
final readonly class SessionPong extends MessageType
{
    public function __construct(
        public string $pingNonce,
        public \DateTimeImmutable $receivedAt,
    ) {
        if ($pingNonce === '') {
            throw new InvalidRequestException('session.pong ping_nonce must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.pong';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'ping_nonce' => $this->pingNonce,
            'received_at' => $this->receivedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $nonce = $data['ping_nonce']
            ?? throw new InvalidRequestException('session.pong ping_nonce missing');
        if (!\is_string($nonce)) {
            throw new InvalidRequestException('session.pong ping_nonce must be string');
        }
        $receivedAt = $data['received_at']
            ?? throw new InvalidRequestException('session.pong received_at missing');
        if (!\is_string($receivedAt)) {
            throw new InvalidRequestException('session.pong received_at must be string');
        }
        try {
            return new self($nonce, new \DateTimeImmutable($receivedAt));
        } catch (\DateMalformedStringException $e) {
            throw new InvalidRequestException(
                'session.pong received_at must be RFC 3339',
                ['received_at' => $receivedAt],
                null,
                $e,
            );
        }
    }
}
