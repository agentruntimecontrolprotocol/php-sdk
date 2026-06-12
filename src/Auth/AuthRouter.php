<?php

declare(strict_types=1);

namespace Arcp\Auth;

use Arcp\Errors\UnimplementedException;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;

/**
 * Routes an inbound auth block to the configured {@see AuthScheme} for
 * its `scheme`. Unknown schemes raise {@see UnimplementedException} so
 * the runtime can convert to `session.rejected`/UNIMPLEMENTED.
 */
final class AuthRouter
{
    /**
     * Schemes reserved by RFC §8.2 but not yet implemented; presenting one
     * surfaces UNIMPLEMENTED rather than a generic unknown-scheme rejection.
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
                throw new UnimplementedException(
                    '§8.2',
                    \sprintf('auth scheme %s deferred to v0.2', $auth->scheme),
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
