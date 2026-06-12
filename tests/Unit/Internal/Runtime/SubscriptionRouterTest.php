<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Internal\Runtime;

use Amp\Future;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Ids\MessageId;
use Arcp\Messages\Control\Ack;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Subscriptions\Unsubscribe;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class SubscriptionRouterTest extends TestCase
{
    /** @return array{0: ARCPRuntime, 1: ARCPClient, 2: Future<mixed>} */
    private function pair(): array
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(subscriptions: true, anonymous: true, features: ['subscribe']));
        return [$runtime, $client, $serverFuture];
    }

    public function testSubscribeWithSessionIdListOutOfScopeDenied(): void
    {
        [, $client, $serverFuture] = $this->pair();
        $caught = null;
        try {
            $client->subscribe(['session_id' => ['sess_other']], fn (): null => null);
        } catch (PermissionDeniedException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertInstanceOf(PermissionDeniedException::class, $caught);
    }

    public function testSubscribeWithSessionIdListMatchingIsAccepted(): void
    {
        [, $client, $serverFuture] = $this->pair();
        $sessionId = $client->session->sessionId;
        self::assertNotNull($sessionId);

        $id = $client->subscribe(
            ['session_id' => [(string) $sessionId]],
            fn (Envelope $env): null => null,
        );
        self::assertNotEmpty((string) $id);

        $client->close();
        $serverFuture->await();
    }

    public function testUnsubscribeWithoutSubscriptionIdIsNackedAsInvalidRequest(): void
    {
        [, $client, $serverFuture] = $this->pair();

        // Send unsubscribe without subscription_id field directly.
        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new Unsubscribe(),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
            // intentionally no subscriptionId
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(Nack::class, $response);
        self::assertSame('INVALID_REQUEST', $response->error->code);

        $client->close();
        $serverFuture->await();
    }

    public function testUnsubscribeKnownSubscriptionReturnsAckClosed(): void
    {
        [, $client, $serverFuture] = $this->pair();
        $subId = $client->subscribe(['types' => ['log']], fn (Envelope $env): null => null);

        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new Unsubscribe(),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
            subscriptionId: $subId,
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(Ack::class, $response);

        $client->close();
        $serverFuture->await();
    }
}
