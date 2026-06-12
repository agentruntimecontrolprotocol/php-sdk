<?php

declare(strict_types=1);

namespace Arcp\Auth;

use Arcp\Errors\UnauthenticatedException;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;

/**
 * Routes an inbound auth block to the configured {@see AuthScheme} for
 * its `scheme`. Unknown or unsupported schemes raise
 * {@see UnauthenticatedException} so the runtime can convert to a
 * `session.rejected`/UNAUTHENTICATED response (ARCP v1.1 §12).
 */
final class AuthRouter
{
    /**
     * Schemes reserved but not yet implemented; presenting one surfaces
     * UNAUTHENTICATED with an explanatory message rather than a generic
     * unknown-scheme rejection.
     *
     * @var list<string>
     */
    private const array RESERVED_SCHEMES = ['mtls', 'oauth2'];

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
            // mTLS and OAuth2 are reserved (RFC §8.2) but unimplemented in v0.1.
            if (\in_array($auth->scheme, self::RESERVED_SCHEMES, true)) {
                throw new UnauthenticatedException(
                    \sprintf('auth scheme %s not supported by this runtime', $auth->scheme),
                );
            }
            return AuthResult::reject('unknown auth scheme: ' . $auth->scheme);
        }
        return $this->schemes[$auth->scheme]->verify($auth, $client);
    }

    public function supports(string $scheme): bool
    {
        return isset($this->schemes[$scheme]);
    }
}
