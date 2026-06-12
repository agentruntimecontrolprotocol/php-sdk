<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Amp\Future;
use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\ArtifactId;
use Arcp\Ids\LeaseId;
use Arcp\Ids\MessageId;
use Arcp\Messages\Execution\AgentDelegate;
use Arcp\Messages\Execution\JobError;
use Arcp\Messages\Execution\JobSchedule;
use Arcp\Messages\Permissions\LeaseExtended;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Messages\Permissions\LeaseRefresh;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for misc runtime/client paths that aren't covered by
 * the topic-specific integration files: ping/pong, deferred-feature
 * top-level job.error rejections, lease refresh, etc.
 */
final class RuntimeMiscTest extends TestCase
{
    /** @return array{0: ARCPRuntime, 1: ARCPClient, 2: Future<mixed>} */
    private function client(): array
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());
        return [$runtime, $client, $serverFuture];
    }

    public function testPingPongRoundTrip(): void
    {
        [, $client, $serverFuture] = $this->client();
        $pong = $client->ping('hello-1', deadlineSeconds: 5.0);
        self::assertSame('hello-1', $pong->pingNonce);
        $client->close();
        $serverFuture->await();
    }

    public function testDeferredFeaturesAreRejectedWithInvalidRequest(): void
    {
        [, $client, $serverFuture] = $this->client();

        // Send a job.schedule envelope manually and expect a correlated
        // top-level job.error (§12).
        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new JobSchedule(['type' => 'tool.invoke'], ['at' => '2026-05-10T13:00:00Z']),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(JobError::class, $response);
        self::assertSame('INVALID_REQUEST', $response->error->code);

        $client->close();
        $serverFuture->await();
    }

    public function testAgentDelegateRejectedAsInvalidRequest(): void
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
        self::assertInstanceOf(JobError::class, $response);
        self::assertSame('INVALID_REQUEST', $response->error->code);
        $client->close();
        $serverFuture->await();
    }

    public function testLeaseRefreshExtendsExistingLease(): void
    {
        [$runtime, $client, $serverFuture] = $this->client();
        $lease = new LeaseGranted(
            new LeaseId('lease_x'),
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
        self::assertInstanceOf(LeaseExtended::class, $response);

        $client->close();
        $serverFuture->await();
    }

    public function testArtifactFetchUnknownIdSurfacesAsJobError(): void
    {
        [, $client, $serverFuture] = $this->client();
        $caught = null;
        try {
            $client->fetchArtifact(new ArtifactId('art_unknown'));
        } catch (InvalidRequestException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertNotNull($caught);
    }
}
