<?php

declare(strict_types=1);

namespace Arcp\Auth;

/**
 * Outcome of an {@see AuthScheme} verification.
 *
 * On success, `principal` is the authenticated identifier (e.g. an email,
 * a service account, or a JWT subject). On failure, `error` carries the
 * `UNAUTHENTICATED` reason.
 */
final readonly class AuthResult
{
    private function __construct(
        public bool $accepted,
        public ?string $principal = null,
        public ?string $error = null,
    ) {
    }

    public static function accept(string $principal): self
    {
        return new self(true, $principal);
    }

    public static function reject(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
