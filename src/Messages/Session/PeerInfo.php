<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Errors\InvalidArgumentException;

/**
 * Identity block for either side of a session (RFC §8.2 / §8.3).
 *
 * Both client (`client`) and runtime (`runtime`) blocks share this shape;
 * the runtime block additionally carries `trust_level`.
 */
final readonly class PeerInfo
{
    public function __construct(
        public string $kind,
        public string $version,
        public ?string $fingerprint = null,
        public ?string $principal = null,
        public ?string $trustLevel = null,
    ) {
        if ($kind === '') {
            throw new InvalidArgumentException('peer.kind must be non-empty');
        }
        if ($version === '') {
            throw new InvalidArgumentException('peer.version must be non-empty');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'kind' => $this->kind,
            'version' => $this->version,
        ];
        if ($this->fingerprint !== null) {
            $out['fingerprint'] = $this->fingerprint;
        }
        if ($this->principal !== null) {
            $out['principal'] = $this->principal;
        }
        if ($this->trustLevel !== null) {
            $out['trust_level'] = $this->trustLevel;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $kind = $data['kind'] ?? throw new InvalidArgumentException('peer.kind missing');
        $version = $data['version'] ?? throw new InvalidArgumentException('peer.version missing');
        if (!\is_string($kind) || !\is_string($version)) {
            throw new InvalidArgumentException('peer.kind/version must be strings');
        }

        $fingerprint = null;
        if (isset($data['fingerprint'])) {
            if (!\is_string($data['fingerprint'])) {
                throw new InvalidArgumentException('peer.fingerprint must be string');
            }
            $fingerprint = $data['fingerprint'];
        }
        $principal = null;
        if (isset($data['principal'])) {
            if (!\is_string($data['principal'])) {
                throw new InvalidArgumentException('peer.principal must be string');
            }
            $principal = $data['principal'];
        }
        $trustLevel = null;
        if (isset($data['trust_level'])) {
            if (!\is_string($data['trust_level'])) {
                throw new InvalidArgumentException('peer.trust_level must be string');
            }
            $trustLevel = $data['trust_level'];
        }
        return new self($kind, $version, $fingerprint, $principal, $trustLevel);
    }
}
