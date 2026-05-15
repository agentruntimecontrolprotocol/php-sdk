<?php

declare(strict_types=1);

namespace Arcp\Cli;

use function Amp\Websocket\Client\connect;

use Amp\Websocket\Client\WebsocketHandshake;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Transport\WebSocketTransport;
use Arcp\Version;
use Revolt\EventLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'tail', description: 'Subscribe to a runtime and print every envelope as JSON')]
final class TailCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('uri', InputArgument::REQUIRED, 'WebSocket URI, e.g. ws://localhost:8765/');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawUri = $input->getArgument('uri');
        if (!\is_string($rawUri) || $rawUri === '') {
            throw new InvalidArgumentException('uri is required');
        }
        $uri = $rawUri;
        $connection = connect(new WebsocketHandshake($uri));
        $serializer = new EnvelopeSerializer(MessageCatalog::create());
        $transport = new WebSocketTransport($connection, $serializer);

        $client = new ARCPClient($transport);
        $client->open(
            Auth::none(),
            new PeerInfo('arcp-tail', Version::IMPL_VERSION),
            new Capabilities(subscriptions: true, anonymous: true),
        );

        $client->subscribe(
            ['types' => []],
            function (Envelope $env) use ($output, $serializer): void {
                $output->writeln(json_encode($serializer->envelopeToArray($env), \JSON_THROW_ON_ERROR));
            },
        );

        EventLoop::run();
        return Command::SUCCESS;
    }
}
