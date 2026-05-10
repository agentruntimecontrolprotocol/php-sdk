<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\SessionId;

/** RFC §8.3 — session established successfully. */
final readonly class SessionAccepted extends MessageType
{
    public function __construct(
        public SessionId $sessionId,
        public Capabilities $capabilities,
        public ?PeerInfo $runtime = null,
        public ?\DateTimeImmutable $leaseExpiresAt = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.accepted';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'session_id' => (string) $this->sessionId,
            'capabilities' => $this->capabilities->toArray(),
        ];
        if ($this->runtime !== null) {
            $out['runtime'] = $this->runtime->toArray();
        }
        if ($this->leaseExpiresAt !== null) {
            $out['lease'] = ['expires_at' => $this->leaseExpiresAt->format(\DateTimeInterface::RFC3339_EXTENDED)];
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $sid = $data['session_id'] ?? throw new InvalidArgumentException('session_id missing');
        $caps = $data['capabilities'] ?? [];
        if (!\is_array($caps)) {
            throw new InvalidArgumentException('capabilities must be object');
        }
        /** @var array<string, mixed> $caps */
        $runtime = null;
        if (isset($data['runtime'])) {
            if (!\is_array($data['runtime'])) {
                throw new InvalidArgumentException('runtime must be object');
            }
            /** @var array<string, mixed> $runtimeData */
            $runtimeData = $data['runtime'];
            $runtime = PeerInfo::fromArray($runtimeData);
        }

        $leaseExp = null;
        if (isset($data['lease'])) {
            if (!\is_array($data['lease']) || !isset($data['lease']['expires_at'])) {
                throw new InvalidArgumentException('lease.expires_at missing');
            }
            $expStr = $data['lease']['expires_at'];
            if (!\is_string($expStr)) {
                throw new InvalidArgumentException('lease.expires_at must be string');
            }
            $leaseExp = new \DateTimeImmutable($expStr);
        }

        return new static(SessionId::fromJson($sid), Capabilities::fromArray($caps), $runtime, $leaseExp);
    }
}
