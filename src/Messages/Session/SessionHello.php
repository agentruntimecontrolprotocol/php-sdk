<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/**
 * ARCP v1.1 §6.2 — initial handshake message (`session.hello`).
 *
 * A client reconnecting after a transport drop presents its most recent
 * `resume_token` and `last_event_seq` (§6.3); the runtime reattaches the
 * parked session and replays buffered events with a greater `event_seq`.
 */
final readonly class SessionHello extends MessageType
{
    public function __construct(
        public Auth $auth,
        public PeerInfo $client,
        public Capabilities $capabilities,
        public ?string $resumeToken = null,
        public ?int $lastEventSeq = null,
    ) {
        if ($lastEventSeq !== null && $lastEventSeq < 0) {
            throw new InvalidRequestException('last_event_seq must be non-negative');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.hello';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'auth' => $this->auth->toArray(),
            'client' => $this->client->toArray(),
            'capabilities' => $this->capabilities->toArray(),
        ];
        if ($this->resumeToken !== null) {
            $out['resume_token'] = $this->resumeToken;
        }
        if ($this->lastEventSeq !== null) {
            $out['last_event_seq'] = $this->lastEventSeq;
        }
        return $out;
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
        $resumeToken = $data['resume_token'] ?? null;
        if ($resumeToken !== null && !\is_string($resumeToken)) {
            throw new InvalidRequestException('resume_token must be string');
        }
        $lastEventSeq = $data['last_event_seq'] ?? null;
        if ($lastEventSeq !== null && !\is_int($lastEventSeq)) {
            throw new InvalidRequestException('last_event_seq must be integer');
        }
        /** @var array<string, mixed> $auth */
        /** @var array<string, mixed> $client */
        /** @var array<string, mixed> $caps */
        return new self(
            Auth::fromArray($auth),
            PeerInfo::fromArray($client),
            Capabilities::fromArray($caps),
            $resumeToken,
            $lastEventSeq,
        );
    }
}
