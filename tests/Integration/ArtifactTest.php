<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Clock\FakeClock;
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
