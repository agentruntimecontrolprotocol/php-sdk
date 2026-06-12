<?php

declare(strict_types=1);

/*
 * subscriptions — three Observer clients attached to one job submitted
 * elsewhere, each routing a slice of its event stream to a sink. None
 * of them ever issue a command (§7.6: subscription does not grant
 * cancel authority).
 *
 * ARCP v1.1 §7.6 (job.subscribe / job.subscribed), §8 (job events).
 */

require __DIR__ . '/../../vendor/autoload.php';

use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Ids\JobId;
use Arcp\Samples\Subscriptions\OtlpSink;
use Arcp\Samples\Subscriptions\SqliteSink;
use Arcp\Samples\Subscriptions\StdoutSink;

require __DIR__ . '/sinks/StdoutSink.php';
require __DIR__ . '/sinks/SqliteSink.php';
require __DIR__ . '/sinks/OtlpSink.php';

const STDOUT_TYPES = [
    'log',
    'job.event',
    'job.progress',
    'job.result',
    'job.error',
];
const OTLP_TYPES = ['metric', 'trace.span'];

/**
 * @param list<string>|null $types Client-side type slice; null = everything.
 * @param callable(Envelope): void $handler
 */
function attach(JobId $jobId, ?array $types, callable $handler): void
{
    /** @var ARCPClient $client */
    $client = elided(); // transport, identity, auth elided
    // §7.6: attach to the job with history replay so late observers see
    // buffered events before the live tail begins.
    $client->subscribe(
        $jobId,
        static function (Envelope $env) use ($types, $handler): void {
            if ($types !== null && !in_array($env->type(), $types, true)) {
                return;
            }
            $handler($env);
        },
        history: true,
    );
    // Run for the job's lifetime; in production the observer client
    // lives in its own process.
    register_shutdown_function(static function () use ($client, $jobId): void {
        $client->unsubscribe($jobId);
        $client->close();
    });
}

function main(): void
{
    $targetJob = new JobId('job_01JABC');
    $stdout = new StdoutSink();
    $otlp = new OtlpSink('https://otlp.internal:4318');
    $sqlite = new SqliteSink('replay.sqlite');

    attach($targetJob, STDOUT_TYPES, [$stdout, 'handle']);
    attach($targetJob, null, [$sqlite, 'handle']);
    attach($targetJob, OTLP_TYPES, [$otlp, 'handle']);
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
