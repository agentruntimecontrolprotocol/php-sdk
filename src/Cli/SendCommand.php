<?php

declare(strict_types=1);

namespace Arcp\Cli;

use Arcp\Errors\InvalidArgumentException;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Envelope\MessageCatalog;
use Arcp\Version;
use function Amp\Websocket\Client\connect;

use Amp\Websocket\Client\WebsocketHandshake;
use Arcp\Client\ARCPClient;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Transport\WebSocketTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'send', description: 'Invoke a tool and print the result')]
final class SendCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('uri', InputArgument::REQUIRED, 'WebSocket URI')
            ->addArgument('tool', InputArgument::REQUIRED, 'Tool name to invoke')
            ->addOption('arguments', 'a', InputOption::VALUE_REQUIRED, 'JSON arguments object', '{}');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawUri = $input->getArgument('uri');
        $rawTool = $input->getArgument('tool');
        $rawArgs = $input->getOption('arguments');
        if (!\is_string($rawUri) || !\is_string($rawTool)) {
            throw new InvalidArgumentException('uri and tool are required');
        }
        $uri = $rawUri;
        $tool = $rawTool;
        $argsJson = \is_string($rawArgs) ? $rawArgs : '{}';
        /** @var array<string, mixed> $args */
        $args = json_decode($argsJson, associative: true, flags: \JSON_THROW_ON_ERROR);

        $connection = connect(new WebsocketHandshake($uri));
        $serializer = new EnvelopeSerializer(MessageCatalog::create());
        $transport = new WebSocketTransport($connection, $serializer);

        $client = new ARCPClient($transport);
        $client->open(
            Auth::none(),
            new PeerInfo('arcp-send', Version::IMPL_VERSION),
            new Capabilities(anonymous: true),
        );

        $result = $client->invokeTool($tool, $args);
        $output->writeln(json_encode($result->value, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        $client->close();
        return Command::SUCCESS;
    }
}
