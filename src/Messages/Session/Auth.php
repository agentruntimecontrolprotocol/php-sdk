<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Errors\InvalidRequestException;

/**
 * Auth credential block carried by `session.hello` (ARCP v1.1 §6.1).
 *
 * §6.1 defines bearer-token authentication; this SDK additionally
 * supports the `anonymous` scheme for development deployments without
 * an auth router. Any other scheme is rejected by the runtime with
 * `UNAUTHENTICATED` (§12). The DTO itself stays permissive so a
 * malformed hello can be answered with the correct error code rather
 * than failing decode.
 */
final readonly class Auth
{
    /** Wire value of the §6.1 bearer-token scheme. */
    public const string BEARER = 'bearer';

    /** Wire value of the unauthenticated development scheme. */
    public const string ANONYMOUS = 'anonymous';

    public function __construct(
        public string $scheme,
        public ?string $token = null,
    ) {
        if ($scheme === '') {
            throw new InvalidRequestException('auth.scheme must be non-empty');
        }
    }

    public static function bearer(string $token): self
    {
        return new self(self::BEARER, $token);
    }

    public static function anonymous(): self
    {
        return new self(self::ANONYMOUS);
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
        $scheme = $data['scheme'] ?? throw new InvalidRequestException('auth.scheme missing');
        if (!\is_string($scheme)) {
            throw new InvalidRequestException('auth.scheme must be string');
        }
        $token = null;
        if (isset($data['token'])) {
            if (!\is_string($data['token'])) {
                throw new InvalidRequestException('auth.token must be string');
            }
            $token = $data['token'];
        }
        return new self($scheme, $token);
    }
}
