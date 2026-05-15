<?php

declare(strict_types=1);

namespace Arcp\Cli;

use Arcp\Envelope\MessageCatalog;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Store\EventLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'replay', description: 'Replay an ARCP event log file from a given message id')]
final class ReplayCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument(
                'database',
                InputArgument::REQUIRED,
                'Path to the SQLite event log database',
            )
            ->addOption(
                'after',
                'a',
                InputOption::VALUE_REQUIRED,
                'Replay after this message id',
                '',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawDb = $input->getArgument('database');
        $rawAfter = $input->getOption('after');
        if (!\is_string($rawDb) || $rawDb === '') {
            throw new InvalidArgumentException('database path is required');
        }
        $db = $rawDb;
        $after = \is_string($rawAfter) ? $rawAfter : '';

        if (!is_file($db)) {
            $output->writeln(\sprintf('<error>database not found: %s</error>', $db));
            return Command::FAILURE;
        }

        $serializer = new EnvelopeSerializer(MessageCatalog::create());
        $log = EventLog::fromFile($db, $serializer);

        $count = 0;
        foreach ($log->replayAfter($after) as $env) {
            $output->writeln($serializer->encode($env));
            ++$count;
        }
        $output->writeln(\sprintf('<info>%d envelopes replayed</info>', $count));
        return Command::SUCCESS;
    }
}
