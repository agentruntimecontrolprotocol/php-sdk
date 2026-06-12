<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Errors\InvalidRequestException;

/**
 * Identity block for either side of a session (ARCP v1.1 §6.2).
 *
 * Both client (`client`) and runtime (`runtime`) blocks share this
 * `{name, version}` shape. The optional fields are SDK extensions: the
 * runtime block may carry `trust_level`, and deployments may attach a
 * `fingerprint`. Nothing security-relevant may trust the self-asserted
 * `principal`; authentication is the §6.1 auth block's job.
 */
final readonly class PeerInfo
{
    public function __construct(
        public string $name,
        public string $version,
        public ?string $fingerprint = null,
        public ?string $principal = null,
        public ?string $trustLevel = null,
    ) {
        if ($name === '') {
            throw new InvalidRequestException('peer.name must be non-empty');
        }
        if ($version === '') {
            throw new InvalidRequestException('peer.version must be non-empty');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
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
        $name = $data['name'] ?? throw new InvalidRequestException('peer.name missing');
        $version = $data['version'] ?? throw new InvalidRequestException('peer.version missing');
        if (!\is_string($name) || !\is_string($version)) {
            throw new InvalidRequestException('peer.name/version must be strings');
        }

        $fingerprint = null;
        if (isset($data['fingerprint'])) {
            if (!\is_string($data['fingerprint'])) {
                throw new InvalidRequestException('peer.fingerprint must be string');
            }
            $fingerprint = $data['fingerprint'];
        }
        $principal = null;
        if (isset($data['principal'])) {
            if (!\is_string($data['principal'])) {
                throw new InvalidRequestException('peer.principal must be string');
            }
            $principal = $data['principal'];
        }
        $trustLevel = null;
        if (isset($data['trust_level'])) {
            if (!\is_string($data['trust_level'])) {
                throw new InvalidRequestException('peer.trust_level must be string');
            }
            $trustLevel = $data['trust_level'];
        }
        return new self($name, $version, $fingerprint, $principal, $trustLevel);
    }
}
