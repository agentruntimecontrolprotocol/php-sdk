<?php

declare(strict_types=1);

namespace Arcp\Auth;

use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Bearer-token verification for JWT-shaped tokens (ARCP v1.1 §6.1).
 * The bearer token presented in `session.hello.payload.auth.token` is
 * decoded as a JWT; the runtime supplies the trust material (HMAC
 * secret or asymmetric public key); we verify `aud` and extract `sub`
 * as the principal.
 */
final readonly class JwtAuth implements AuthScheme
{
    private LoggerInterface $logger;

    public function __construct(
        private Key $key,
        private string $audience,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    #[\Override]
    public function name(): string
    {
        return Auth::BEARER;
    }

    #[\Override]
    public function verify(Auth $auth, PeerInfo $client): AuthResult
    {
        if ($auth->scheme !== Auth::BEARER) {
            return AuthResult::reject('scheme mismatch');
        }
        if ($auth->token === null || $auth->token === '') {
            return AuthResult::reject('missing token');
        }
        try {
            $decoded = JWT::decode($auth->token, $this->key);
        } catch (\Throwable $e) {
            // Keep the wire reason opaque: the underlying decode error can
            // leak key id, algorithm, or expiry details useful to an
            // attacker probing trust material. Log it server-side instead.
            $this->logger->info('jwt verification failed', ['error' => $e->getMessage()]);

            return AuthResult::reject('jwt verification failed');
        }
        $claims = (array) $decoded;
        $aud = $claims['aud'] ?? null;
        if (\is_array($aud)) {
            if (!\in_array($this->audience, $aud, true)) {
                return AuthResult::reject('aud mismatch');
            }
        } elseif ($aud !== $this->audience) {
            return AuthResult::reject('aud mismatch');
        }
        $sub = $claims['sub'] ?? null;
        if (!\is_string($sub) || $sub === '') {
            return AuthResult::reject('jwt missing sub');
        }
        return AuthResult::accept($sub);
    }
}
