<?php

declare(strict_types=1);

namespace Arcp\Auth;

use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;

/**
 * Opaque bearer-token verification (RFC §8.2). The runtime keeps a token
 * → principal mapping; presence of the token grants the principal.
 */
final class BearerAuth implements AuthScheme
{
    /** @param array<string, string> $tokens token → principal */
    public function __construct(private readonly array $tokens)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'bearer';
    }

    #[\Override]
    public function verify(Auth $auth, PeerInfo $client): AuthResult
    {
        if ($auth->scheme !== 'bearer') {
            return AuthResult::reject('scheme mismatch');
        }
        if ($auth->token === null || $auth->token === '') {
            return AuthResult::reject('missing token');
        }
        if (!isset($this->tokens[$auth->token])) {
            return AuthResult::reject('invalid token');
        }
        $principal = $client->principal ?? $this->tokens[$auth->token];
        return AuthResult::accept($principal);
    }
}
