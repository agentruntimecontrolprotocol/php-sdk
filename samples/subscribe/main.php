<?php

declare(strict_types=1);

/*
 * subscriptions — three Observer clients on one producing session,
 * three filters, three sinks. None of them ever issue a command.
 *
 * RFC §5 (roles), §13 (subscriptions, filters, backfill).
 */

require __DIR__ . '/../../vendor/autoload.php';

use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Samples\Subscriptions\OtlpSink;
use Arcp\Samples\Subscriptions\SqliteSink;
use Arcp\Samples\Subscriptions\StdoutSink;

require __DIR__ . '/sinks/StdoutSink.php';
require __DIR__ . '/sinks/SqliteSink.php';
require __DIR__ . '/sinks/OtlpSink.php';

const STDOUT_TYPES = [
    'log',
    'job.started',
    'job.progress',
    'job.completed',
    'job.failed',
    'tool.error',
];
const OTLP_TYPES = ['metric', 'trace.span'];

/**
 * @param list<string>|null $types
 * @param callable(Envelope): void $handler
 */
function attach(string $sessionId, ?array $types, callable $handler): void
{
    /** @var ARCPClient $client */
    $client = elided(); // transport, identity, auth elided
    $filter = ['session_id' => [$sessionId]];
    if ($types !== null) {
        $filter['types'] = $types;
    }
    $sub = $client->subscribe($filter, static function (Envelope $env) use ($handler): void {
        $handler($env);
    });
    // Run for the producing session's lifetime; in production the
    // observer client lives in its own process.
    register_shutdown_function(static function () use ($client, $sub): void {
        $client->unsubscribe($sub);
        $client->close();
    });
}

function main(): void
{
    $targetSession = '...';
    $stdout = new StdoutSink();
    $otlp = new OtlpSink('https://otlp.internal:4318');
    $sqlite = new SqliteSink('replay.sqlite');

    attach($targetSession, STDOUT_TYPES, [$stdout, 'handle']);
    attach($targetSession, null, [$sqlite, 'handle']);
    attach($targetSession, OTLP_TYPES, [$otlp, 'handle']);
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
