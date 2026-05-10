<?php

declare(strict_types=1);

namespace Arcp\Auth;

use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * `signed_jwt` verification (RFC §8.2). The runtime supplies the trust
 * material (HMAC secret or asymmetric public key); we verify `aud` and
 * extract `sub` as the principal.
 */
final class JwtAuth implements AuthScheme
{
    public function __construct(
        private readonly Key $key,
        private readonly string $audience,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'signed_jwt';
    }

    #[\Override]
    public function verify(Auth $auth, PeerInfo $client): AuthResult
    {
        if ($auth->scheme !== 'signed_jwt') {
            return AuthResult::reject('scheme mismatch');
        }
        if ($auth->token === null || $auth->token === '') {
            return AuthResult::reject('missing token');
        }
        try {
            $decoded = JWT::decode($auth->token, $this->key);
        } catch (\Throwable $e) {
            return AuthResult::reject('jwt verification failed: ' . $e->getMessage());
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
