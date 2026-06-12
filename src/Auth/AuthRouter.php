<?php

declare(strict_types=1);

namespace Arcp\Auth;

use Arcp\Errors\UnauthenticatedException;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;

/**
 * Routes an inbound auth block to the configured {@see AuthScheme} for
 * its `scheme`. §6.1 defines `bearer` (plus this SDK's `anonymous`
 * extension); any other scheme is rejected so the runtime surfaces
 * UNAUTHENTICATED (ARCP v1.1 §12).
 */
final class AuthRouter
{
    /** @var array<string, AuthScheme> */
    private array $schemes = [];

    /** @param iterable<AuthScheme> $schemes */
    public function __construct(iterable $schemes = [])
    {
        foreach ($schemes as $scheme) {
            $this->schemes[$scheme->name()] = $scheme;
        }
    }

    public function register(AuthScheme $scheme): void
    {
        $this->schemes[$scheme->name()] = $scheme;
    }

    public function verify(Auth $auth, PeerInfo $client): AuthResult
    {
        if (!isset($this->schemes[$auth->scheme])) {
            // §6.1: only `bearer` (and the `anonymous` extension) exist;
            // anything else is rejected as UNAUTHENTICATED (§12).
            throw new UnauthenticatedException(
                \sprintf('auth scheme %s not supported by this runtime', $auth->scheme),
            );
        }
        return $this->schemes[$auth->scheme]->verify($auth, $client);
    }

    public function supports(string $scheme): bool
    {
        return isset($this->schemes[$scheme]);
    }
}
