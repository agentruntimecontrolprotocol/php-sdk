<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Internal\Runtime;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Errors\UnimplementedException;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class HandshakeNegotiatorTest extends TestCase
{
    public function testAgentHandoffCapabilityMismatchRejected(): void
    {
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                Auth::none(),
                new PeerInfo('cli', '0.1'),
                new Capabilities(agentHandoff: true, anonymous: true),
            );
            self::fail('expected UnimplementedException');
        } catch (UnimplementedException $e) {
            self::assertStringContainsString('agent_handoff', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testCheckpointsCapabilityMismatchRejected(): void
    {
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                Auth::none(),
                new PeerInfo('cli', '0.1'),
                new Capabilities(checkpoints: true, anonymous: true),
            );
            self::fail('expected UnimplementedException');
        } catch (UnimplementedException $e) {
            self::assertStringContainsString('checkpoints', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testNoAuthRouterNonAnonymousIsUnauthenticated(): void
    {
        // No auth router; non-anonymous request with `none` scheme.
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                Auth::none(),
                new PeerInfo('cli', '0.1'),
                new Capabilities(), // anonymous=false
            );
            self::fail('expected UnauthenticatedException');
        } catch (UnauthenticatedException $e) {
            self::assertStringContainsString('no auth router', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testNoAuthRouterAnonymousNoneIsAccepted(): void
    {
        // No auth router but client asks for anonymous + none scheme.
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::none(),
            new PeerInfo('cli', '0.1', principal: 'p'),
            new Capabilities(anonymous: true),
        );
        self::assertNotEmpty((string) $accepted->sessionId);

        $client->close();
        $serverFuture->await();
    }

    public function testMtlsAuthRouterReturnsUnimplemented(): void
    {
        // AuthRouter does not register mtls scheme; mtls is reserved -> UnimplementedException.
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new NoneAuth()]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                new Auth('mtls'),
                new PeerInfo('cli', '0.1'),
                new Capabilities(anonymous: true),
            );
            self::fail('expected UnimplementedException');
        } catch (UnimplementedException $e) {
            self::assertStringContainsString('mtls', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }
}
