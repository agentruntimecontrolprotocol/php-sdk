<?php

declare(strict_types=1);

namespace Arcp\Cli;

use function Amp\Websocket\Client\connect;

use Amp\Websocket\Client\WebsocketHandshake;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\JobId;
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

#[AsCommand(name: 'tail', description: 'Subscribe to a job and print every envelope as JSON')]
final class TailCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument(
            'uri',
            InputArgument::REQUIRED,
            'WebSocket URI, e.g. ws://localhost:8765/',
        );
        $this->addArgument(
            'job-id',
            InputArgument::REQUIRED,
            'Job id to attach to (§7.6 job.subscribe)',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawUri = $input->getArgument('uri');
        if (!\is_string($rawUri) || $rawUri === '') {
            throw new InvalidRequestException('uri is required');
        }
        $uri = $rawUri;
        $connection = connect(new WebsocketHandshake($uri));
        $serializer = new EnvelopeSerializer(MessageCatalog::create());
        $transport = new WebSocketTransport($connection, $serializer);

        $client = new ARCPClient($transport);
        $client->open(
            Auth::anonymous(),
            new PeerInfo('arcp-tail', Version::IMPL_VERSION),
            new Capabilities(features: ['subscribe']),
        );

        $rawJobId = $input->getArgument('job-id');
        if (!\is_string($rawJobId) || $rawJobId === '') {
            throw new InvalidRequestException('job-id is required');
        }
        $client->subscribe(
            new JobId($rawJobId),
            function (Envelope $env) use ($output, $serializer): void {
                $output->writeln(
                    json_encode($serializer->envelopeToArray($env), \JSON_THROW_ON_ERROR),
                );
            },
            history: true,
        );

        EventLoop::run();
        return Command::SUCCESS;
    }
}
