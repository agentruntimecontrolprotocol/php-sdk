<?php

declare(strict_types=1);

/*
 * cancellation — two scenarios over the §7.4 control surface:
 *  - `job.cancel`: cooperative termination; runtime acknowledges with
 *    `job.cancelled` and the job terminates with `job.error`
 *    (code CANCELLED, final_status "cancelled").
 *  - `interrupt`:  route through a human, no termination (SDK extension).
 */

require __DIR__ . '/../../vendor/autoload.php';

use function Amp\delay;

use Arcp\Client\ARCPClient;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Messages\Control\Interrupt;

function startLongJob(ARCPClient $client): JobId
{
    // job.submit that the runtime promotes into a long-running job.
    // Real impl reads payload.job_id from the job.accepted envelope
    // (sent before the terminal job.result resolves).
    $client->invokeTool('demo.long_running', ['work_seconds' => 600]);
    throw new \RuntimeException('not implemented');
}

/**
 * Cooperative cancel (§7.4). The runtime acknowledges with
 * `job.cancelled` and the job terminates with `job.error`
 * (code CANCELLED, final_status "cancelled").
 */
function cancelJob(ARCPClient $client, JobId $jobId, string $reason): void
{
    try {
        $client->cancelJob($jobId, $reason);
    } catch (InvalidRequestException $e) {
        // Cancelling an already-terminal job nacks with INVALID_REQUEST.
        throw $e;
    }
}

/**
 * Distinct from cancel: the runtime emits `human.input.request` and the
 * job keeps running (§7.3 has no blocked state); NOT terminated.
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
    // Real impl uses $client->subscribe($jobId, ...) (§7.6) and returns
    // the first envelope whose type ∈ {job.result, job.error}.
    throw new \RuntimeException('not implemented');
}

function scenarioCancel(): void
{
    /** @var ARCPClient $client */
    $client = elided(); // transport, identity, auth elided
    try {
        $jobId = startLongJob($client);
        delay(2.0); // let the job actually start
        cancelJob($client, $jobId, 'user_aborted');
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
        // Runtime now emits a blocking request; a caller decides how to
        // respond. Observe the job's stream for it (§7.6).
        $client->subscribe(
            $jobId,
            static function (Envelope $env): void {
                if ($env->type() === 'human.input.request') {
                    printf("awaiting human: %s\n", $env->type());
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
