<?php

declare(strict_types=1);

/*
 * delegation — fan a request out to peer runtimes; tolerate partial
 * failure. JobMux demuxes inbound events by `job_id`.
 *
 * RFC §14 (agent.delegate), §6.4 (envelope correlation), §17.1 (trace).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/synth.php';

use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Ids\JobId;
use Arcp\Ids\TraceId;

use function Arcp\Samples\Delegation\synthesize;

const PEERS = ['research.web', 'research.code', 'research.docs'];
const TERMINAL_TYPES = ['job.result', 'job.error'];

final class Job
{
    public ?string $jobId = null;
    /** @var array<string, mixed>|null */
    public ?array $final = null;
    /** @var array<string, mixed>|null */
    public ?array $error = null;

    public function __construct(public readonly string $target)
    {
    }
}

function delegate(ARCPClient $client, string $target, string $task, TraceId $traceId): Job
{
    $job = new Job($target);
    // Real impl: send `agent.delegate`, await `job.accepted`.
    // On a job.error rejection, populate $job->error and return.
    throw new \RuntimeException('not implemented');
}

/**
 * Single reader on `$client`'s inbound stream; fans out by `job_id`.
 *
 * Without this, parallel readers on one client starve each other.
 */
final class JobMux
{
    /** @var array<string, list<Envelope>> */
    private array $buffers = [];

    public function __construct(private readonly ARCPClient $client)
    {
    }

    public function register(string $jobId): void
    {
        $this->buffers[$jobId] = [];
        // §7.6: subscriptions are job-scoped — attach one per delegated job
        // and route into the per-job buffer.
        $this->client->subscribe(
            new JobId($jobId),
            function (Envelope $env) use ($jobId): void {
                if (!in_array($env->type(), array_merge(['job.event', 'job.heartbeat', 'job.progress'], TERMINAL_TYPES), true)) {
                    return;
                }
                $this->buffers[$jobId][] = $env;
                // Terminal envelopes wake any awaiter; in production a
                // dedicated Future per job is resolved here.
            },
        );
    }

    public function collect(Job $job): Job
    {
        if ($job->error !== null || $job->jobId === null) {
            return $job;
        }
        $jid = $job->jobId;
        $buffered = $this->buffers[$jid] ?? [];
        // Drain $buffered until a terminal envelope arrives. Real impl
        // awaits a future resolved by the subscription callback above.
        unset($buffered);
        throw new \RuntimeException('not implemented');
    }
}

function main(): void
{
    /** @var ARCPClient $client */
    $client = elided(); // transport, identity, auth elided

    $mux = new JobMux($client);

    $request = 'what changed in our auth stack in the last 30 days?';
    $traceId = TraceId::random();

    $jobs = [];
    foreach (PEERS as $peer) {
        $job = delegate($client, $peer, $request, $traceId);
        if ($job->jobId !== null) {
            $mux->register($job->jobId);
        }
        $jobs[] = $job;
    }

    $completed = array_map(static fn (Job $j) => $mux->collect($j), $jobs);
    echo synthesize($request, $completed), "\n";

    $client->close();
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
