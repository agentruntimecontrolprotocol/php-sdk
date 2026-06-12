<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Internal\Runtime;

use Amp\Cancellation;
use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Client\ARCPClient;
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
    public function testUnbackedFeatureIsIntersectedAway(): void
    {
        // §6.2: the effective feature set is the intersection of hello and
        // welcome features. Requesting a feature the runtime does not back
        // is not an error; it is simply absent from the welcome.
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::anonymous(),
            new PeerInfo('cli', '0.1'),
            new Capabilities(features: ['heartbeat', 'checkpoints', 'agent_handoff']),
        );
        self::assertSame(['heartbeat'], $accepted->capabilities->features);

        $client->close();
        $serverFuture->await();
    }

    public function testEncodingsIntersected(): void
    {
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::anonymous(),
            new PeerInfo('cli', '0.1'),
            new Capabilities(encodings: ['json', 'cbor']),
        );
        self::assertSame(['json'], $accepted->capabilities->encodings);

        $client->close();
        $serverFuture->await();
    }

    public function testNoAuthRouterBearerIsUnauthenticated(): void
    {
        // No auth router: there is nothing to verify a bearer token
        // against, so bearer hellos are rejected UNAUTHENTICATED (§6.1).
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                Auth::bearer('tok'),
                new PeerInfo('cli', '0.1'),
                new Capabilities(),
            );
            self::fail('expected UnauthenticatedException');
        } catch (UnauthenticatedException $e) {
            self::assertStringContainsString('no auth router', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testNoAuthRouterAnonymousIsAccepted(): void
    {
        // No auth router but client uses the anonymous scheme.
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $accepted = $client->open(
            Auth::anonymous(),
            new PeerInfo('cli', '0.1', principal: 'p'),
            new Capabilities(),
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
        $clientA->open(Auth::anonymous(), new PeerInfo('cli', '0.1', principal: 'alice'), new Capabilities());
        $clientB->open(Auth::anonymous(), new PeerInfo('cli', '0.1', principal: 'alice'), new Capabilities());

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

    public function testMtlsAuthRouterReturnsUnauthenticated(): void
    {
        // §6.1 defines bearer (plus the SDK's anonymous extension);
        // any other scheme -> UNAUTHENTICATED (§12).
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new AnonymousAuth()]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                new Auth('mtls'),
                new PeerInfo('cli', '0.1'),
                new Capabilities(),
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
