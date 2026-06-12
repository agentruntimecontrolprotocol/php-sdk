<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\BearerAuth;
use Arcp\Client\ARCPClient;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class HandshakeTest extends TestCase
{
    public function testBearerHandshakeSucceeds(): void
    {
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new BearerAuth(['t-good' => 'alice'])]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::bearer('t-good'),
            new PeerInfo('test-cli', '0.1'),
            new Capabilities(),
        );

        self::assertNotEmpty((string) $accepted->sessionId);

        $client->close();
        $serverFuture->await();
    }

    public function testBearerTokenIgnoresClientSuppliedPrincipal(): void
    {
        // Server-side regression: even when the client claims a different
        // principal in PeerInfo, BearerAuth must use the token-mapped one.
        $scheme = new BearerAuth(['t-good' => 'alice']);
        $result = $scheme->verify(
            Auth::bearer('t-good'),
            new PeerInfo('test-cli', '0.1', principal: 'mallory@example.com'),
        );
        self::assertTrue($result->accepted);
        self::assertSame('alice', $result->principal);
    }

    public function testBearerWithBadTokenIsRejected(): void
    {
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new BearerAuth(['t-good' => 'alice'])]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                Auth::bearer('not-a-token'),
                new PeerInfo('test-cli', '0.1'),
                new Capabilities(),
            );
            self::fail('expected UnauthenticatedException');
        } catch (UnauthenticatedException $e) {
            self::assertStringContainsString('invalid token', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testAnonymousNoneScheme(): void
    {
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new AnonymousAuth('public-user')]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::anonymous(),
            new PeerInfo('test-cli', '0.1'),
            new Capabilities(),
        );

        self::assertNotEmpty((string) $accepted->sessionId);
        $client->close();
        $serverFuture->await();
    }

    public function testFeatureIntersectionExcludesUnbackedFeatures(): void
    {
        // §6.2: features outside the runtime's advertised set are
        // intersected away rather than rejected; the client MUST NOT use
        // anything missing from the welcome's feature list.
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new AnonymousAuth()]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::anonymous(),
            new PeerInfo('test-cli', '0.1'),
            new Capabilities(features: ['list_jobs', 'scheduled_jobs']),
        );
        self::assertSame(['list_jobs'], $accepted->capabilities->features);
        self::assertSame(['json'], $accepted->capabilities->encodings);

        $client->close();
        $serverFuture->await();
    }

    public function testUnsupportedAuthSchemeIsUnauthenticated(): void
    {
        // §6.1/§12: only `bearer` (and the SDK's `anonymous` extension)
        // are honored; anything else is UNAUTHENTICATED.
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new AnonymousAuth()]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                new Auth('oauth2', 'tok'),
                new PeerInfo('test-cli', '0.1'),
                new Capabilities(),
            );
            self::fail('expected UnauthenticatedException');
        } catch (UnauthenticatedException $e) {
            self::assertStringContainsString('unsupported auth scheme', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testRuntimeIdentityReturnedToClient(): void
    {
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new AnonymousAuth()]),
            runtimeIdentity: new PeerInfo('example-runtime', '1.2.3', trustLevel: 'privileged'),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::anonymous(),
            new PeerInfo('test-cli', '0.1'),
            new Capabilities(),
        );

        self::assertNotNull($accepted->runtime);
        self::assertSame('example-runtime', $accepted->runtime->name);
        self::assertSame('privileged', $accepted->runtime->trustLevel);

        $client->close();
        $serverFuture->await();
    }
}
