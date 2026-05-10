<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Amp\Cancellation;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Ids\MessageId;
use Arcp\Messages\Control\Resume;
use Arcp\Messages\Execution\AgentDelegate;
use Arcp\Messages\Execution\JobSchedule;
use Arcp\Messages\Permissions\LeaseRefresh;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for misc runtime/client paths that aren't covered by
 * the topic-specific integration files: ping/pong, deferred-feature
 * nacks, resume, lease refresh, etc.
 */
final class RuntimeMiscTest extends TestCase
{
    /** @return array{0: ARCPRuntime, 1: ARCPClient, 2: \Amp\Future<mixed>} */
    private function client(): array
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));
        return [$runtime, $client, $serverFuture];
    }

    public function testPingPongRoundTrip(): void
    {
        [, $client, $serverFuture] = $this->client();
        $pong = $client->ping('hello-1', deadlineSeconds: 5.0);
        self::assertSame('hello-1', $pong->nonce);
        $client->close();
        $serverFuture->await();
    }

    public function testDeferredFeaturesAreNackedWithUnimplemented(): void
    {
        [, $client, $serverFuture] = $this->client();

        // Send a job.schedule envelope manually and expect a nack.
        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new JobSchedule(['type' => 'tool.invoke'], ['at' => '2026-05-10T13:00:00Z']),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(\Arcp\Messages\Control\Nack::class, $response);
        self::assertSame('UNIMPLEMENTED', $response->error->code);

        $client->close();
        $serverFuture->await();
    }

    public function testAgentDelegateNacksAsUnimplemented(): void
    {
        [, $client, $serverFuture] = $this->client();
        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new AgentDelegate(['target' => 'r2']),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(\Arcp\Messages\Control\Nack::class, $response);
        self::assertSame('UNIMPLEMENTED', $response->error->code);
        $client->close();
        $serverFuture->await();
    }

    public function testResumeAfterMessageIdReplaysEvents(): void
    {
        [$runtime, $client, $serverFuture] = $this->client();
        $runtime->registerTool('seed', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->reportProgress(50);
                return null;
            }
        });
        $client->invokeTool('seed');

        // Issue a resume to walk events from the beginning.
        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new Resume(afterMessageId: ''),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(\Arcp\Messages\Control\Ack::class, $response);

        $client->close();
        $serverFuture->await();
    }

    public function testLeaseRefreshExtendsExistingLease(): void
    {
        [$runtime, $client, $serverFuture] = $this->client();
        $lease = new \Arcp\Messages\Permissions\LeaseGranted(
            new \Arcp\Ids\LeaseId('lease_x'),
            'p',
            'r',
            'op',
            new \DateTimeImmutable()->modify('+1 hour'),
        );
        $runtime->leases->register($lease);

        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new LeaseRefresh($lease->leaseId, 600),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(\Arcp\Messages\Permissions\LeaseExtended::class, $response);

        $client->close();
        $serverFuture->await();
    }

    public function testArtifactFetchUnknownIdSurfacesAsNack(): void
    {
        [, $client, $serverFuture] = $this->client();
        $caught = null;
        try {
            $client->fetchArtifact(new \Arcp\Ids\ArtifactId('art_unknown'));
        } catch (\Arcp\Errors\NotFoundException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertNotNull($caught);
    }
}
