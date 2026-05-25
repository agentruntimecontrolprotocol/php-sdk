<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\BearerAuth;
use Arcp\Auth\JwtAuth;
use Arcp\Auth\NoneAuth;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\UnimplementedException;
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
        $result = $scheme->verify(Auth::none(), new PeerInfo('c', '0'));
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

    public function testNoneAccepts(): void
    {
        $scheme = new NoneAuth('public');
        $result = $scheme->verify(Auth::none(), new PeerInfo('c', '0'));
        self::assertTrue($result->accepted);
        self::assertSame('public', $result->principal);
    }

    public function testNoneUsesProvidedPrincipal(): void
    {
        $scheme = new NoneAuth('public');
        $result = $scheme->verify(Auth::none(), new PeerInfo('c', '0', principal: 'someone@x'));
        self::assertSame('someone@x', $result->principal);
    }

    public function testNoneRejectsWrongScheme(): void
    {
        $scheme = new NoneAuth();
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
        $result = $scheme->verify(Auth::signedJwt($token), new PeerInfo('c', '0'));
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
        $result = $scheme->verify(Auth::signedJwt($token), new PeerInfo('c', '0'));
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
        $result = $scheme->verify(Auth::signedJwt($token), new PeerInfo('c', '0'));
        self::assertFalse($result->accepted);
    }

    public function testJwtRejectsMissingToken(): void
    {
        $scheme = new JwtAuth(new Key('s', 'HS256'), 'aud');
        $result = $scheme->verify(new Auth('signed_jwt'), new PeerInfo('c', '0'));
        self::assertFalse($result->accepted);
    }

    public function testRouterDispatchesToScheme(): void
    {
        $router = new AuthRouter([new BearerAuth(['t' => 'alice']), new NoneAuth('pub')]);
        self::assertTrue($router->supports('bearer'));
        self::assertTrue($router->supports('none'));
        self::assertFalse($router->supports('mtls'));

        $r = $router->verify(Auth::bearer('t'), new PeerInfo('c', '0'));
        self::assertTrue($r->accepted);
    }

    public function testRouterRaisesUnimplementedForReservedSchemes(): void
    {
        $router = new AuthRouter([new NoneAuth()]);
        $this->expectException(UnimplementedException::class);
        $router->verify(new Auth('mtls'), new PeerInfo('c', '0'));
    }

    public function testRouterRejectsUnknownNonReservedScheme(): void
    {
        $router = new AuthRouter([new NoneAuth()]);
        $r = $router->verify(new Auth('made-up'), new PeerInfo('c', '0'));
        self::assertFalse($r->accepted);
    }

    public function testAuthRoundTrip(): void
    {
        $a = Auth::bearer('t');
        $back = Auth::fromArray($a->toArray());
        self::assertEquals($a, $back);

        $n = Auth::none();
        $nBack = Auth::fromArray($n->toArray());
        self::assertEquals($n, $nBack);

        $j = Auth::signedJwt('jwt-token');
        $jBack = Auth::fromArray($j->toArray());
        self::assertEquals($j, $jBack);
    }

    public function testAuthRequiresScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Auth('');
    }
}
