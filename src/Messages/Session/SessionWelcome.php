<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\SessionId;

/**
 * ARCP v1.1 §6.2 — session established successfully (`session.welcome`).
 *
 * Carries the §6.3 resume parameters (`resume_token`, rotated on every
 * successful welcome, and `resume_window_sec`) plus the §6.4
 * `heartbeat_interval_sec` when the heartbeat feature was negotiated.
 */
final readonly class SessionWelcome extends MessageType
{
    public function __construct(
        public SessionId $sessionId,
        public Capabilities $capabilities,
        public ?PeerInfo $runtime = null,
        public ?\DateTimeImmutable $leaseExpiresAt = null,
        public ?string $resumeToken = null,
        public ?int $resumeWindowSec = null,
        public ?int $heartbeatIntervalSec = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.welcome';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'session_id' => (string) $this->sessionId,
            'capabilities' => $this->capabilities->toArray(),
        ];
        if ($this->runtime instanceof PeerInfo) {
            $out['runtime'] = $this->runtime->toArray();
        }
        if ($this->resumeToken !== null) {
            $out['resume_token'] = $this->resumeToken;
        }
        if ($this->resumeWindowSec !== null) {
            $out['resume_window_sec'] = $this->resumeWindowSec;
        }
        if ($this->heartbeatIntervalSec !== null) {
            $out['heartbeat_interval_sec'] = $this->heartbeatIntervalSec;
        }
        if ($this->leaseExpiresAt instanceof \DateTimeImmutable) {
            $out['lease'] = [
                'expires_at' => $this->leaseExpiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            ];
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $sid = $data['session_id'] ?? throw new InvalidRequestException('session_id missing');
        $caps = $data['capabilities'] ?? [];
        if (!\is_array($caps)) {
            throw new InvalidRequestException('capabilities must be object');
        }
        /** @var array<string, mixed> $caps */
        return new self(
            SessionId::fromJson($sid),
            Capabilities::fromArray($caps),
            self::extractRuntime($data),
            self::extractLeaseExpiry($data),
            self::optionalString($data, 'resume_token'),
            self::optionalInt($data, 'resume_window_sec'),
            self::optionalInt($data, 'heartbeat_interval_sec'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!\is_string($data[$key])) {
            throw new InvalidRequestException($key . ' must be string');
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function optionalInt(array $data, string $key): ?int
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!\is_int($data[$key])) {
            throw new InvalidRequestException($key . ' must be integer');
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function extractRuntime(array $data): ?PeerInfo
    {
        if (!isset($data['runtime'])) {
            return null;
        }
        if (!\is_array($data['runtime'])) {
            throw new InvalidRequestException('runtime must be object');
        }
        /** @var array<string, mixed> $runtimeData */
        $runtimeData = $data['runtime'];
        return PeerInfo::fromArray($runtimeData);
    }

    /** @param array<string, mixed> $data */
    private static function extractLeaseExpiry(array $data): ?\DateTimeImmutable
    {
        if (!isset($data['lease'])) {
            return null;
        }
        if (!\is_array($data['lease']) || !isset($data['lease']['expires_at'])) {
            throw new InvalidRequestException('lease.expires_at missing');
        }
        $expStr = $data['lease']['expires_at'];
        if (!\is_string($expStr)) {
            throw new InvalidRequestException('lease.expires_at must be string');
        }
        return new \DateTimeImmutable($expStr);
    }
}
