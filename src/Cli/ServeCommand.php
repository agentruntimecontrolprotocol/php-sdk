<?php

declare(strict_types=1);

namespace Arcp\Cli;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Transport\WebSocketTransport;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve', description: 'Run an ARCP runtime accepting WebSocket connections')]
final class ServeCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Listen host', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Listen port', '8765');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawHost = $input->getOption('host');
        $rawPort = $input->getOption('port');
        $host = \is_string($rawHost) ? $rawHost : '127.0.0.1';
        $port = \is_string($rawPort) ? (int) $rawPort : (\is_int($rawPort) ? $rawPort : 8765);

        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $logger = new NullLogger();
        $http = SocketHttpServer::createForDirectAccess($logger);
        $http->expose(\sprintf('%s:%d', $host, $port));

        $serializer = $runtime->serializer;
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

        $websocket = new Websocket($http, $logger, new Rfc6455Acceptor(), $clientHandler);
        $http->start($websocket, new DefaultErrorHandler());
        $output->writeln(\sprintf('<info>arcp listening on ws://%s:%d/</info>', $host, $port));

        // Block; pressing Ctrl-C kills the process. Production deployments
        // should wire SIGINT/SIGTERM via the EventLoop driver.
        \Revolt\EventLoop::run();
        return Command::SUCCESS;
    }
}
