<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** ARCP v1.1 §6.2 — initial handshake message (`session.hello`). */
final readonly class SessionHello extends MessageType
{
    public function __construct(
        public Auth $auth,
        public PeerInfo $client,
        public Capabilities $capabilities,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.hello';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'auth' => $this->auth->toArray(),
            'client' => $this->client->toArray(),
            'capabilities' => $this->capabilities->toArray(),
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $auth = $data['auth'] ?? throw new InvalidRequestException('session.hello auth missing');
        $client = $data['client']
            ?? throw new InvalidRequestException('session.hello client missing');
        $caps = $data['capabilities'] ?? [];
        if (!\is_array($auth) || !\is_array($client) || !\is_array($caps)) {
            throw new InvalidRequestException('session.hello fields must be objects');
        }
        /** @var array<string, mixed> $auth */
        /** @var array<string, mixed> $client */
        /** @var array<string, mixed> $caps */
        return new self(
            Auth::fromArray($auth),
            PeerInfo::fromArray($client),
            Capabilities::fromArray($caps),
        );
    }
}
