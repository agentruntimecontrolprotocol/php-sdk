<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Arcp\Clock\FakeClock;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\SessionId;
use Arcp\Runtime\ArtifactBlob;
use Arcp\Runtime\ArtifactStore;
use Arcp\Runtime\Session;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class ArtifactStoreTest extends TestCase
{
    private function session(): Session
    {
        [$a] = MemoryTransport::pair();
        $session = new Session($a);
        $session->sessionId = SessionId::random();

        return $session;
    }

    public function testRefRejectsAndEvictsExpiredArtifact(): void
    {
        $clock = new FakeClock();
        $store = new ArtifactStore($clock);
        $session = $this->session();
        $ref = $store->put($session, new ArtifactBlob('text/plain', 'hi', retentionSeconds: 10));

        // Still live.
        self::assertSame($ref->artifactId, $store->ref($ref->artifactId, $session)->artifactId);

        $clock->advance(20);
        try {
            $store->ref($ref->artifactId, $session);
            self::fail('expected InvalidRequestException for expired artifact');
        } catch (InvalidRequestException) {
            // ref() must agree with fetch() and drop the expired row (#79).
        }
        // The expired row is gone, so a subsequent lookup is also a miss.
        $this->expectException(InvalidRequestException::class);
        $store->ref($ref->artifactId, $session);
    }
}
