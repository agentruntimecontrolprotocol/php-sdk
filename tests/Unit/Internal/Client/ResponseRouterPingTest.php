<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Internal\Client;

use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Internal\Client\HumanHandlers;
use Arcp\Internal\Client\ResponseRouter;
use Arcp\Internal\Client\ResponseRouterDeps;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Session\SessionPing;
use Arcp\Messages\Session\SessionPong;
use Arcp\Runtime\PendingRegistry;
use Arcp\Runtime\Session;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ResponseRouterPingTest extends TestCase
{
    public function testInboundPingIsAnsweredWithCorrelatedPong(): void
    {
        [$clientT, $peerT] = MemoryTransport::pair();
        $session = new Session($clientT, isClient: true);
        $session->sessionId = new SessionId('sess_x');
        $deps = new ResponseRouterDeps(
            $clientT,
            $session,
            new PendingRegistry(),
            new EnvelopeSerializer(MessageCatalog::create()),
            new SystemClock(),
            new NullLogger(),
        );
        // No human/permission handlers registered: the pong must still fire.
        $router = new ResponseRouter($deps, new HumanHandlers(fn () => null, fn () => null));

        $pingId = new MessageId('msg_ping');
        $router->handle(new Envelope(
            id: $pingId,
            payload: new SessionPing('nonce-1', new \DateTimeImmutable('2026-05-09T13:00:00Z')),
            timestamp: new \DateTimeImmutable(),
        ));

        $reply = $peerT->receive();
        self::assertInstanceOf(Envelope::class, $reply);
        self::assertInstanceOf(SessionPong::class, $reply->payload);
        self::assertSame('nonce-1', $reply->payload->pingNonce);
        self::assertNotNull($reply->correlationId);
        self::assertSame('msg_ping', (string) $reply->correlationId);
    }
}
