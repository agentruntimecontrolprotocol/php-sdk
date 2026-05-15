<?php

declare(strict_types=1);

namespace Arcp\Auth;

use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;

/**
 * `none` scheme (RFC §8.2). Only valid if the client requested
 * `capabilities.anonymous: true` AND the runtime advertises it.
 */
final readonly class NoneAuth implements AuthScheme
{
    public function __construct(private string $defaultPrincipal = 'anonymous')
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'none';
    }

    #[\Override]
    public function verify(Auth $auth, PeerInfo $client): AuthResult
    {
        if ($auth->scheme !== 'none') {
            return AuthResult::reject('scheme mismatch');
        }
        return AuthResult::accept($client->principal ?? $this->defaultPrincipal);
    }
}
