<?php

declare(strict_types=1);

/*
 * heartbeats — supervisor + worker pool. Heartbeat loss reroutes via
 * `idempotency_key`; the surviving worker dedupes the duplicate.
 *
 * RFC §10.3 (heartbeat-loss recovery), §6.4 (idempotency), §14 (delegate).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/work.php';

use function Amp\async;
use function Amp\delay;

use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobHeartbeat;

use function Arcp\Samples\Heartbeats\doWork;

const HEARTBEAT_INTERVAL_SECONDS = 15;
const DEADLINE_S = HEARTBEAT_INTERVAL_SECONDS * 2; // RFC §10.3 default N=2

final class Worker
{
    public ?string $inFlightJob = null;

    public function __construct(
        public readonly string $workerId,
        public readonly string $role,
        public \DateTimeImmutable $lastHeartbeat,
    ) {
    }
}

final class Task
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $taskId,
        public readonly string $role,
        public readonly array $payload,
        public readonly IdempotencyKey $idempotencyKey, // safety net for re-dispatch
    ) {
    }
}

final class Roster
{
    /** @var array<string, Worker> */
    public array $workers = [];

    public function add(Worker $w): void
    {
        $this->workers[$w->workerId] = $w;
    }

    /** @return list<Worker> */
    public function candidates(string $role): array
    {
        return array_values(array_filter(
            $this->workers,
            static fn (Worker $w) => $w->role === $role && $w->inFlightJob === null,
        ));
    }
}

// Supervisor side --------------------------------------------------------

/**
 * @param array<string, Task> $jobsToTasks
 */
function dispatch(ARCPClient $supervisor, Task $task, Roster $roster, array &$jobsToTasks): void
{
    $candidates = $roster->candidates($task->role);
    if ($candidates === []) {
        throw new \RuntimeException("no idle workers for role={$task->role}");
    }
    usort($candidates, static fn ($a, $b) => $a->lastHeartbeat <=> $b->lastHeartbeat);
    $worker = $candidates[0];
    // Same idempotency_key on every re-dispatch (RFC §6.4): a worker
    // that survived the network blip dedupes; it doesn't re-execute.
    // Real impl: invokeTool('agent.delegate', ..., idempotencyKey: $task->idempotencyKey)
    // and read job_id from the JobAccepted reply.
    throw new \RuntimeException('not implemented');
}

/**
 * @param array<string, Task> $jobsToTasks
 */
/**
 * @param array<string, Task> $jobsToTasks
 */
function reaper(ARCPClient $supervisor, Roster $roster, array &$jobsToTasks): void
{
    while ($supervisor->session->state !== \Arcp\Runtime\SessionState::Closed) {
        delay(HEARTBEAT_INTERVAL_SECONDS);
        $now = new \DateTimeImmutable('now');
        foreach ($roster->workers as $w) {
            if ($now->getTimestamp() - $w->lastHeartbeat->getTimestamp() <= DEADLINE_S) {
                continue;
            }
            $jid = $w->inFlightJob;
            $task = $jid !== null ? ($jobsToTasks[$jid] ?? null) : null;
            if ($task !== null && $jid !== null) {
                unset($jobsToTasks[$jid]);
                dispatch($supervisor, $task, $roster, $jobsToTasks);
            }
            unset($roster->workers[$w->workerId]);
        }
    }
}

// Worker side ------------------------------------------------------------

/**
 * @return \Closure(): void
 */
function heartbeatLoop(ARCPClient $worker, JobId $jobId): \Closure
{
    return static function () use ($worker, $jobId): void {
        // Real impl emits a JobHeartbeat envelope on $jobId every
        // HEARTBEAT_INTERVAL_SECONDS until cancelled by the caller
        // (typically via the Future returned by async()).
        unset($jobId);
        while ($worker->session->state !== \Arcp\Runtime\SessionState::Closed) {
            delay(HEARTBEAT_INTERVAL_SECONDS);
        }
    };
}

function execute(ARCPClient $worker, Envelope $delegate): void
{
    $jobId = JobId::random();
    // Send job.accepted (correlation_id=$delegate->id), job.started.
    $hb = async(heartbeatLoop($worker, $jobId));
    try {
        $msg = $delegate->payload;
        $taskPayload = [];
        if ($msg instanceof \Arcp\Messages\Execution\AgentDelegate) {
            $ctx = $msg->payload['context'] ?? null;
            $tp = is_array($ctx) ? ($ctx['task_payload'] ?? null) : null;
            if (is_array($tp)) {
                /** @var array<string, mixed> $taskPayload */
                $taskPayload = $tp;
            }
        }
        $result = doWork($taskPayload);
        // Send job.completed.
    } catch (\Throwable $e) {
        // Send job.failed with INTERNAL + retryable=true.
    } finally {
        $hb->ignore();
    }
    throw new \RuntimeException('not implemented');
}

function main(): void
{
    /** @var ARCPClient $supervisor */
    $supervisor = elided(); // transport, identity (privileged), auth elided
    $roster = new Roster();
    /** @var array<string, Task> $jobsToTasks */
    $jobsToTasks = [];

    foreach (['indexer', 'extractor', 'archiver'] as $role) {
        for ($n = 0; $n < 2; $n++) {
            $w = elided(); // worker session, capabilities advertise role
            // Each worker drains its own inbound and calls execute()
            // for every agent.delegate envelope. Wiring elided.
            async(static fn () => execute($w, dummyInboundDelegate()));
            $roster->add(new Worker(
                "{$role}-" . bin2hex(random_bytes(3)),
                $role,
                new \DateTimeImmutable('now'),
            ));
        }
    }

    async(static function () use ($supervisor, $roster, &$jobsToTasks): void {
        reaper($supervisor, $roster, $jobsToTasks);
    });

    $roles = ['indexer', 'extractor', 'archiver'];
    for ($n = 0; $n < 6; $n++) {
        dispatch($supervisor, new Task(
            taskId: sprintf('t%03d', $n),
            role: $roles[$n % 3],
            payload: ['shard' => $n],
            idempotencyKey: new IdempotencyKey(sprintf('openclaw:t%03d', $n)),
        ), $roster, $jobsToTasks);
    }

    delay(60);
    $supervisor->close();
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

function dummyInboundDelegate(): Envelope
{
    throw new \RuntimeException('not implemented');
}

main();
