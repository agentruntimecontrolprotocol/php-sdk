<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\BearerAuth;
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

use function Amp\async;

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
            new PeerInfo('test-cli', '0.1', principal: 'alice@example.com'),
            new Capabilities(streaming: true, humanInput: true),
        );

        self::assertNotNull($accepted->sessionId);
        self::assertSame('alice@example.com', $client->session->principal);

        $client->close();
        $serverFuture->await();
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
            authRouter: new AuthRouter([new NoneAuth('public-user')]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::none(),
            new PeerInfo('test-cli', '0.1'),
            new Capabilities(anonymous: true),
        );

        self::assertNotNull($accepted->sessionId);
        $client->close();
        $serverFuture->await();
    }

    public function testCapabilityMismatchYieldsUnimplemented(): void
    {
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new NoneAuth()]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                Auth::none(),
                new PeerInfo('test-cli', '0.1'),
                new Capabilities(scheduledJobs: true, anonymous: true),
            );
            self::fail('expected UnimplementedException');
        } catch (UnimplementedException $e) {
            self::assertStringContainsString('scheduled_jobs', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testRuntimeIdentityReturnedToClient(): void
    {
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new NoneAuth()]),
            runtimeIdentity: new PeerInfo('openclaw', '1.2.3', trustLevel: 'privileged'),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::none(),
            new PeerInfo('test-cli', '0.1'),
            new Capabilities(anonymous: true),
        );

        self::assertNotNull($accepted->runtime);
        self::assertSame('openclaw', $accepted->runtime->kind);
        self::assertSame('privileged', $accepted->runtime->trustLevel);

        $client->close();
        $serverFuture->await();
    }
}
