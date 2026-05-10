<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Amp\Http\Server\Request;
use Amp\Http\Server\SocketHttpServer;

use function Amp\Websocket\Client\connect;

use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Transport\WebSocketTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebSocketTransportTest extends TestCase
{
    public function testHandshakeOverRealWebsocket(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $serializer = $runtime->serializer;

        $logger = new NullLogger();
        $http = SocketHttpServer::createForDirectAccess($logger);
        $http->expose('127.0.0.1:0');

        $clientHandler = new class ($runtime, $serializer) implements WebsocketClientHandler {
            public function __construct(
                private readonly ARCPRuntime $runtime,
                private readonly EnvelopeSerializer $serializer,
            ) {
            }

            #[\Override]
            public function handleClient(WebsocketClient $client, Request $request, \Amp\Http\Server\Response $response): void
            {
                $transport = new WebSocketTransport($client, $this->serializer);
                $this->runtime->serve($transport);
            }
        };

        $websocket = new Websocket($http, $logger, new Rfc6455Acceptor(), $clientHandler);
        $http->start($websocket, new \Amp\Http\Server\DefaultErrorHandler());

        $address = $http->getServers()[0]->getAddress();
        // SocketAddress can be either Internet (host+port) or Unix (path);
        // toString() always exposes the canonical form like "127.0.0.1:54321".
        $hostPort = $address->toString();
        $handshake = new WebsocketHandshake('ws://' . $hostPort . '/');
        $connection = connect($handshake);
        $clientTransport = new WebSocketTransport($connection, $serializer);

        $client = new ARCPClient($clientTransport, $runtime->registry);
        $accepted = $client->open(
            Auth::none(),
            new PeerInfo('ws-client', '0.1'),
            new Capabilities(anonymous: true),
        );
        self::assertNotEmpty((string) $accepted->sessionId);

        $client->close();
        $http->stop();
    }
}
