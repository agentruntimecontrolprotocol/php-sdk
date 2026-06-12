<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Clock\FakeClock;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class ArtifactTest extends TestCase
{
    public function testPutFetchReleaseRoundTrip(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(artifacts: true, anonymous: true));

        $bytes = "hello-world\nthis is some content";
        $ref = $client->putArtifact('text/plain', $bytes);
        self::assertSame('text/plain', $ref->mediaType);
        self::assertSame(\strlen($bytes), $ref->size);

        $fetched = $client->fetchArtifact($ref->artifactId);
        self::assertSame($bytes, $fetched);

        $client->close();
        $serverFuture->await();
    }

    public function testPutWithMatchingSha256Succeeds(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(artifacts: true, anonymous: true));

        $bytes = 'verified-content';
        $digest = hash('sha256', $bytes);
        $ref = $client->putArtifact('text/plain', $bytes, sha256: $digest);
        self::assertSame($digest, $ref->sha256);

        $client->close();
        $serverFuture->await();
    }

    public function testPutWithMismatchedSha256IsRejected(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(artifacts: true, anonymous: true));

        $caught = null;
        try {
            $client->putArtifact(
                'text/plain',
                'real-content',
                sha256: hash('sha256', 'different-content'),
            );
        } catch (\Arcp\Errors\InvalidRequestException $e) {
            $caught = $e;
        }
        self::assertInstanceOf(\Arcp\Errors\InvalidRequestException::class, $caught);
        self::assertStringContainsString('sha256', $caught->getMessage());
        self::assertSame(0, $runtime->artifacts->count());

        $client->close();
        $serverFuture->await();
    }

    public function testPutWithMalformedSha256IsRejected(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(artifacts: true, anonymous: true));

        $caught = null;
        try {
            $client->putArtifact('text/plain', 'real-content', sha256: 'not-a-real-hex-digest');
        } catch (\Arcp\Errors\InvalidRequestException $e) {
            $caught = $e;
        }
        self::assertInstanceOf(\Arcp\Errors\InvalidRequestException::class, $caught);

        $client->close();
        $serverFuture->await();
    }

    public function testCrossSessionFetchIsDenied(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverTA, $clientTA] = MemoryTransport::pair();
        [$serverTB, $clientTB] = MemoryTransport::pair();
        $serverFutureA = $runtime->serveAsync($serverTA);
        $serverFutureB = $runtime->serveAsync($serverTB);
        $clientA = new ARCPClient($clientTA);
        $clientB = new ARCPClient($clientTB);
        $clientA->open(Auth::none(), new PeerInfo('cli-a', '0.1'), new Capabilities(artifacts: true, anonymous: true));
        $clientB->open(Auth::none(), new PeerInfo('cli-b', '0.1'), new Capabilities(artifacts: true, anonymous: true));

        $ref = $clientA->putArtifact('text/plain', 'secret');

        $caught = null;
        try {
            $clientB->fetchArtifact($ref->artifactId);
        } catch (PermissionDeniedException $e) {
            $caught = $e;
        }
        self::assertInstanceOf(PermissionDeniedException::class, $caught);

        $clientA->close();
        $clientB->close();
        $serverFutureA->await();
        $serverFutureB->await();
    }

    public function testCrossSessionReleaseIsDenied(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverTA, $clientTA] = MemoryTransport::pair();
        [$serverTB, $clientTB] = MemoryTransport::pair();
        $serverFutureA = $runtime->serveAsync($serverTA);
        $serverFutureB = $runtime->serveAsync($serverTB);
        $clientA = new ARCPClient($clientTA);
        $clientB = new ARCPClient($clientTB);
        $clientA->open(Auth::none(), new PeerInfo('cli-a', '0.1'), new Capabilities(artifacts: true, anonymous: true));
        $clientB->open(Auth::none(), new PeerInfo('cli-b', '0.1'), new Capabilities(artifacts: true, anonymous: true));

        $ref = $clientA->putArtifact('text/plain', 'secret');
        self::assertSame(1, $runtime->artifacts->count());

        $caught = null;
        try {
            $clientB->releaseArtifact($ref->artifactId);
        } catch (PermissionDeniedException $e) {
            $caught = $e;
        }
        self::assertInstanceOf(PermissionDeniedException::class, $caught);
        // Artifact is still around for the owner.
        self::assertSame(1, $runtime->artifacts->count());

        $clientA->close();
        $clientB->close();
        $serverFutureA->await();
        $serverFutureB->await();
    }

    public function testRetentionSweepRemovesExpiredArtifacts(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-05-09T12:00:00Z'));
        $runtime = new ARCPRuntime(
            clock: $clock,
            authRouter: new AuthRouter([new NoneAuth()]),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT, clock: $clock);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(artifacts: true, anonymous: true));

        $client->putArtifact('text/plain', 'short-lived', retentionSeconds: 60);
        self::assertSame(1, $runtime->artifacts->count());

        $clock->advance(120);
        $removed = $runtime->artifacts->sweep();
        self::assertSame(1, $removed);
        self::assertSame(0, $runtime->artifacts->count());

        $client->close();
        $serverFuture->await();
    }
}
