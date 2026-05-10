<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Errors\InvalidArgumentException;

/**
 * Auth credential block carried by `session.open` (RFC §8.2).
 *
 * Schemes recognized in v0.1: `bearer`, `signed_jwt`, `none`.
 * `mtls` and `oauth2` are accepted on the wire but the runtime will
 * reject them with `UNIMPLEMENTED` until v0.2.
 */
final readonly class Auth
{
    public function __construct(
        public string $scheme,
        public ?string $token = null,
    ) {
        if ($scheme === '') {
            throw new InvalidArgumentException('auth.scheme must be non-empty');
        }
    }

    public static function bearer(string $token): self
    {
        return new self('bearer', $token);
    }

    public static function signedJwt(string $token): self
    {
        return new self('signed_jwt', $token);
    }

    public static function none(): self
    {
        return new self('none');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['scheme' => $this->scheme];
        if ($this->token !== null) {
            $out['token'] = $this->token;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $scheme = $data['scheme'] ?? throw new InvalidArgumentException('auth.scheme missing');
        if (!\is_string($scheme)) {
            throw new InvalidArgumentException('auth.scheme must be string');
        }
        $token = null;
        if (isset($data['token'])) {
            if (!\is_string($data['token'])) {
                throw new InvalidArgumentException('auth.token must be string');
            }
            $token = $data['token'];
        }
        return new self($scheme, $token);
    }
}
