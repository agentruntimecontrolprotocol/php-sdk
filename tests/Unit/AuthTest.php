<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\BearerAuth;
use Arcp\Auth\JwtAuth;
use Arcp\Errors\InvalidRequestException;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    public function testBearerAccepts(): void
    {
        $scheme = new BearerAuth(['t1' => 'alice']);
        $client = new PeerInfo('cli', '0.1');
        $result = $scheme->verify(Auth::bearer('t1'), $client);
        self::assertTrue($result->accepted);
        self::assertSame('alice', $result->principal);
    }

    public function testBearerRejectsMissingToken(): void
    {
        $scheme = new BearerAuth(['t1' => 'alice']);
        $result = $scheme->verify(new Auth('bearer'), new PeerInfo('c', '0'));
        self::assertFalse($result->accepted);
    }

    public function testBearerRejectsBadToken(): void
    {
        $scheme = new BearerAuth(['t1' => 'alice']);
        $result = $scheme->verify(Auth::bearer('wrong'), new PeerInfo('c', '0'));
        self::assertFalse($result->accepted);
    }

    public function testBearerRejectsWrongScheme(): void
    {
        $scheme = new BearerAuth(['t1' => 'alice']);
        $result = $scheme->verify(Auth::anonymous(), new PeerInfo('c', '0'));
        self::assertFalse($result->accepted);
    }

    public function testBearerIgnoresClientSuppliedPrincipal(): void
    {
        $scheme = new BearerAuth(['t1' => 'alice']);
        $result = $scheme->verify(
            Auth::bearer('t1'),
            new PeerInfo('c', '0', principal: 'mallory@example.com'),
        );
        self::assertTrue($result->accepted);
        self::assertSame('alice', $result->principal);
    }

    public function testAnonymousAccepts(): void
    {
        $scheme = new AnonymousAuth('public');
        $result = $scheme->verify(Auth::anonymous(), new PeerInfo('c', '0'));
        self::assertTrue($result->accepted);
        self::assertSame('public', $result->principal);
    }

    public function testAnonymousIgnoresPeerSuppliedPrincipal(): void
    {
        $scheme = new AnonymousAuth('public');
        $result = $scheme->verify(Auth::anonymous(), new PeerInfo('c', '0', principal: 'someone@x'));
        // The peer-supplied principal must not be trusted (#67).
        self::assertSame('public', $result->principal);
    }

    public function testAnonymousRejectsWrongScheme(): void
    {
        $scheme = new AnonymousAuth();
        $result = $scheme->verify(Auth::bearer('t'), new PeerInfo('c', '0'));
        self::assertFalse($result->accepted);
    }

    public function testJwtVerifies(): void
    {
        $secret = 'this-is-a-super-secret-key-with-at-least-32-bytes!!';
        $token = JWT::encode([
            'iss' => 'tester',
            'sub' => 'alice@example.com',
            'aud' => 'arcp-runtime',
            'iat' => time(),
            'exp' => time() + 60,
        ], $secret, 'HS256');

        $scheme = new JwtAuth(new Key($secret, 'HS256'), 'arcp-runtime');
        $result = $scheme->verify(Auth::bearer($token), new PeerInfo('c', '0'));
        self::assertTrue($result->accepted);
        self::assertSame('alice@example.com', $result->principal);
    }

    public function testJwtRejectsWrongAudience(): void
    {
        $secret = 'this-is-a-super-secret-key-with-at-least-32-bytes!!';
        $token = JWT::encode([
            'sub' => 'alice',
            'aud' => 'other-runtime',
            'iat' => time(),
            'exp' => time() + 60,
        ], $secret, 'HS256');

        $scheme = new JwtAuth(new Key($secret, 'HS256'), 'arcp-runtime');
        $result = $scheme->verify(Auth::bearer($token), new PeerInfo('c', '0'));
        self::assertFalse($result->accepted);
    }

    public function testJwtRejectsBadSignature(): void
    {
        $token = JWT::encode([
            'sub' => 'alice',
            'aud' => 'arcp-runtime',
            'iat' => time(),
            'exp' => time() + 60,
        ], 'wrong-secret-needs-to-be-at-least-32-bytes-long!', 'HS256');

        $scheme = new JwtAuth(
            new Key('correct-secret-needs-to-be-at-least-32-bytes-long!', 'HS256'),
            'arcp-runtime',
        );
        $result = $scheme->verify(Auth::bearer($token), new PeerInfo('c', '0'));
        self::assertFalse($result->accepted);
    }

    public function testJwtRejectsMissingToken(): void
    {
        $scheme = new JwtAuth(new Key('s', 'HS256'), 'aud');
        $result = $scheme->verify(new Auth('bearer'), new PeerInfo('c', '0'));
        self::assertFalse($result->accepted);
    }

    public function testRouterDispatchesToScheme(): void
    {
        $router = new AuthRouter([new BearerAuth(['t' => 'alice']), new AnonymousAuth('pub')]);
        self::assertTrue($router->supports('bearer'));
        self::assertTrue($router->supports('anonymous'));
        self::assertFalse($router->supports('mtls'));

        $r = $router->verify(Auth::bearer('t'), new PeerInfo('c', '0'));
        self::assertTrue($r->accepted);
    }

    public function testRouterRaisesUnauthenticatedForUnregisteredScheme(): void
    {
        // §6.1/§12: schemes outside {bearer, anonymous} (or without a
        // registered handler) are UNAUTHENTICATED.
        $router = new AuthRouter([new AnonymousAuth()]);
        $this->expectException(UnauthenticatedException::class);
        $router->verify(new Auth('mtls'), new PeerInfo('c', '0'));
    }

    public function testAuthRoundTrip(): void
    {
        $a = Auth::bearer('t');
        $back = Auth::fromArray($a->toArray());
        self::assertEquals($a, $back);

        $n = Auth::anonymous();
        $nBack = Auth::fromArray($n->toArray());
        self::assertEquals($n, $nBack);
    }

    public function testAuthRequiresScheme(): void
    {
        $this->expectException(InvalidRequestException::class);
        new Auth('');
    }
}
