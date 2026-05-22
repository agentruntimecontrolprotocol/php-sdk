<?php

declare(strict_types=1);

/*
 * cancellation — two scenarios over the §10.4 / §10.5 control surface:
 *  - `cancel`:    cooperative termination with a deadline.
 *  - `interrupt`: pause the job and route through a human, no termination.
 */

require __DIR__ . '/../../vendor/autoload.php';

use function Amp\delay;

use Arcp\Client\ARCPClient;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\FailedPreconditionException;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Messages\Control\Interrupt;

const CANCEL_DEADLINE_MS = 5_000;

function startLongJob(ARCPClient $client): JobId
{
    // tool.invoke that the runtime promotes into a long-running job.
    // Real impl reads the job id from the JobAccepted (sent
    // synchronously alongside the ToolResult future).
    $client->invokeTool('demo.long_running', ['work_seconds' => 600]);
    throw new \RuntimeException('not implemented');
}

/**
 * Cooperative cancel. Runtime drives target to a clean checkpoint
 * inside `deadline_ms` before terminating; escalates to ABORTED on
 * timeout (RFC §10.4).
 */
function cancelJob(ARCPClient $client, JobId $jobId, string $reason, int $deadlineMs): void
{
    try {
        $client->cancelJob($jobId, $reason, $deadlineMs);
    } catch (FailedPreconditionException $e) {
        // Surfaced as cancel.refused → FailedPrecondition by the client.
        throw $e;
    }
}

/**
 * Distinct from cancel: pauses the job (`blocked`), runtime emits
 * `human.input.request`. Job is NOT terminated (RFC §10.5).
 */
function interruptJob(ARCPClient $client, JobId $jobId, string $prompt): void
{
    $client->session->transport->send(new Envelope(
        id: MessageId::random(),
        payload: new Interrupt(target: 'job', targetId: (string) $jobId, prompt: $prompt),
        timestamp: new SystemClock()->now(),
        sessionId: $client->session->sessionId,
        jobId: $jobId,
    ));
}

function awaitTerminal(ARCPClient $client, JobId $jobId): Envelope
{
    // Real impl subscribes to the session, filters by jobId, returns
    // the first envelope whose type ∈ {job.completed, job.failed,
    // job.cancelled}.
    throw new \RuntimeException('not implemented');
}

function scenarioCancel(): void
{
    /** @var ARCPClient $client */
    $client = elided(); // transport, identity, auth elided
    try {
        $jobId = startLongJob($client);
        delay(2.0); // let the job actually start
        cancelJob($client, $jobId, 'user_aborted', CANCEL_DEADLINE_MS);
        echo "cancel ack\n";
        $terminal = awaitTerminal($client, $jobId);
        printf("terminal: %s\n", $terminal->type());
    } finally {
        $client->close();
    }
}

function scenarioInterrupt(): void
{
    /** @var ARCPClient $client */
    $client = elided();
    try {
        $jobId = startLongJob($client);
        delay(2.0);
        interruptJob($client, $jobId, 'Pause and ask before touching production tables.');
        // Runtime now emits a blocking request; a caller decides whether to resume.
        $client->subscribe(
            ['types' => ['human.input.request']],
            static function (Envelope $env) use ($jobId): void {
                if ($env->jobId !== null && (string) $env->jobId === (string) $jobId) {
                    printf("awaiting human: %s\n", $env->payload::typeName());
                }
            },
        );
    } finally {
        $client->close();
    }
}

function main(): void
{
    /** @var list<string> $argv */
    global $argv;
    $which = isset($argv[1]) ? (string) $argv[1] : 'cancel';
    if ($which === 'cancel') {
        scenarioCancel();
    } elseif ($which === 'interrupt') {
        scenarioInterrupt();
    } else {
        fwrite(STDERR, "unknown scenario: {$which}\n");
        exit(1);
    }
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
