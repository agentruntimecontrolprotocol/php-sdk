<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Internal\Runtime;

use Amp\Cancellation;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Errors\InvalidRequestException;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Ids\IdempotencyKey;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
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
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
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
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
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

    public function testAnonymousPrincipalIsServerAssignedAndIsolated(): void
    {
        // Two router-less sessions both *claim* principal 'alice' in their
        // untrusted PeerInfo. The server must ignore that and assign an
        // opaque per-session principal, so the second client's invocation
        // with the same idempotency key does NOT replay the first's outcome.
        $count = 0;
        $runtime = new ARCPRuntime();
        $runtime->registerTool('once', new class ($count) implements ToolHandler {
            public function __construct(private int &$count)
            {
            }

            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $this->count += 1;
                return ['ran' => $this->count];
            }
        });

        [$serverTA, $clientTA] = MemoryTransport::pair();
        [$serverTB, $clientTB] = MemoryTransport::pair();
        $futureA = $runtime->serveAsync($serverTA);
        $futureB = $runtime->serveAsync($serverTB);
        $clientA = new ARCPClient($clientTA);
        $clientB = new ARCPClient($clientTB);
        $clientA->open(Auth::none(), new PeerInfo('cli', '0.1', principal: 'alice'), new Capabilities(anonymous: true));
        $clientB->open(Auth::none(), new PeerInfo('cli', '0.1', principal: 'alice'), new Capabilities(anonymous: true));

        $key = new IdempotencyKey('shared-key');
        $first = $clientA->invokeTool('once', [], idempotencyKey: $key);
        $second = $clientB->invokeTool('once', [], idempotencyKey: $key);

        self::assertSame(['ran' => 1], $first->result);
        self::assertSame(['ran' => 2], $second->result, 'distinct principals must not share idempotency');

        $clientA->close();
        $clientB->close();
        $futureA->await();
        $futureB->await();
    }

    public function testNonStringRequiredFeatureIsRejected(): void
    {
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                Auth::none(),
                new PeerInfo('cli', '0.1'),
                new Capabilities(anonymous: true, extra: ['required_features' => [123]]),
            );
            self::fail('expected InvalidRequestException for non-string required feature');
        } catch (InvalidRequestException $e) {
            self::assertStringContainsString('required_features entry must be string', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testMtlsAuthRouterReturnsUnauthenticated(): void
    {
        // AuthRouter does not register mtls scheme; mtls is reserved ->
        // UNAUTHENTICATED (§12: missing or invalid authentication).
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
            self::fail('expected UnauthenticatedException');
        } catch (UnauthenticatedException $e) {
            self::assertStringContainsString('mtls', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }
}
