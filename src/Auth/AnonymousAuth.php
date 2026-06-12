<?php

declare(strict_types=1);

namespace Arcp\Auth;

use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;

/**
 * `anonymous` scheme (ARCP v1.1 §6.1 extension). Accepts the session
 * without credential material; intended for development deployments.
 */
final readonly class AnonymousAuth implements AuthScheme
{
    public function __construct(private string $defaultPrincipal = 'anonymous')
    {
    }

    #[\Override]
    public function name(): string
    {
        return Auth::ANONYMOUS;
    }

    #[\Override]
    public function verify(Auth $auth, PeerInfo $client): AuthResult
    {
        if ($auth->scheme !== Auth::ANONYMOUS) {
            return AuthResult::reject('scheme mismatch');
        }
        // Always use the configured default principal; do not trust the
        // principal supplied in the untrusted PeerInfo block (mirrors the
        // BearerAuth contract). Trusting it would let any anonymous peer
        // claim an arbitrary principal and bypass per-principal isolation.
        return AuthResult::accept($this->defaultPrincipal);
    }
}
