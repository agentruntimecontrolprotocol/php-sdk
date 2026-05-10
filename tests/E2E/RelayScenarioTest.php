<?php

declare(strict_types=1);

namespace Arcp\Tests\E2E;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
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
use Arcp\Client\Handlers\CallbackHumanInputHandler;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanChoiceResponse;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use Arcp\Transport\StdioTransport;
use Arcp\Transport\Transport;
use Arcp\Transport\WebSocketTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end relay scenario: a tool requests human input, the relay
 * provides an answer, the tool emits progress and produces a result.
 * Runs against every transport that is mandatory in v0.1 (RFC §22).
 */
final class RelayScenarioTest extends TestCase
{
    /** @return iterable<string, array{0: \Closure(): array{0: ARCPRuntime, 1: Transport, 2: \Closure(): void}}> */
    public static function transports(): iterable
    {
        yield 'memory' => [
            static function (): array {
                $runtime = self::buildRuntime();
                [$serverT, $clientT] = MemoryTransport::pair();
                $future = $runtime->serveAsync($serverT);
                $cleanup = static function () use ($future): void {
                    $future->await();
                };
                return [$runtime, $clientT, $cleanup];
            },
        ];

        yield 'stdio' => [
            static function (): array {
                $runtime = self::buildRuntime();
                $serializer = $runtime->serializer;

                $pair1 = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
                $pair2 = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
                if ($pair1 === false || $pair2 === false) {
                    throw new \RuntimeException('cannot create stream socket pair');
                }
                $serverT = new StdioTransport(
                    new ReadableResourceStream($pair1[0]),
                    new WritableResourceStream($pair2[0]),
                    $serializer,
                );
                $clientT = new StdioTransport(
                    new ReadableResourceStream($pair2[1]),
                    new WritableResourceStream($pair1[1]),
                    $serializer,
                );
                $future = $runtime->serveAsync($serverT);
                $cleanup = static function () use ($future): void {
                    $future->await();
                };
                return [$runtime, $clientT, $cleanup];
            },
        ];

        yield 'websocket' => [
            static function (): array {
                $runtime = self::buildRuntime();
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
                    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
                    {
                        $transport = new WebSocketTransport($client, $this->serializer);
                        $this->runtime->serve($transport);
                    }
                };
                $ws = new Websocket($http, $logger, new Rfc6455Acceptor(), $clientHandler);
                $http->start($ws, new DefaultErrorHandler());
                $hostPort = $http->getServers()[0]->getAddress()->toString();
                $connection = connect(new WebsocketHandshake('ws://' . $hostPort . '/'));
                $clientT = new WebSocketTransport($connection, $serializer);
                $cleanup = static function () use ($http): void {
                    $http->stop();
                };
                return [$runtime, $clientT, $cleanup];
            },
        ];
    }

    private static function buildRuntime(): ARCPRuntime
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('handle_failed_tests', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->reportProgress(10, 'asking human');
                $resp = $ctx->requestHumanChoice(
                    'Three test files failed. How should I proceed?',
                    [
                        ['id' => 'fix', 'label' => 'Fix and re-run'],
                        ['id' => 'skip', 'label' => 'Skip and continue'],
                        ['id' => 'abort', 'label' => 'Abort the job'],
                    ],
                    new \DateTimeImmutable('+5 minutes'),
                );
                $ctx->reportProgress(95, 'finishing');
                return ['choice' => $resp->choiceId, 'responded_by' => $resp->respondedBy];
            }
        });
        return $runtime;
    }

    /**
     * @param \Closure(): array{0: ARCPRuntime, 1: Transport, 2: \Closure(): void} $factory
     */
    #[DataProvider('transports')]
    public function testRelayScenarioAcrossTransports(\Closure $factory): void
    {
        [$runtime, $clientT, $cleanup] = $factory();
        $relay = new CallbackHumanInputHandler(
            onInput: fn (HumanInputRequest $r) => new HumanInputResponse(null),
            onChoice: fn (HumanChoiceRequest $r) => new HumanChoiceResponse('fix', 'relay:slack', new \DateTimeImmutable()),
        );
        $client = new ARCPClient($clientT, humanInputHandler: $relay);
        $client->open(Auth::none(), new PeerInfo('e2e', '0.1'), new Capabilities(humanInput: true, anonymous: true));

        $result = $client->invokeTool('handle_failed_tests');
        self::assertIsArray($result->value);
        self::assertSame('fix', $result->value['choice'] ?? null);
        self::assertSame('relay:slack', $result->value['responded_by'] ?? null);

        $client->close();
        $cleanup();
    }
}
