<?php

declare(strict_types=1);

/*
 * resumability — durable research job with real crash and resume.
 *
 *   # First call: crash after `synthesize`. Prints the resume token.
 *   CRASH_AFTER_STEP=synthesize php samples/resume/main.php
 *
 *   # Second call: pick up from the printed checkpoint.
 *   RESUME_JOB_ID=...  RESUME_AFTER_MSG_ID=...  RESUME_CHECKPOINT_ID=... \
 *     php samples/resume/main.php
 *
 * RFC §10 (job lifecycle), §19 (resumability), §6.4 (idempotency),
 * §18.2 (DATA_LOSS on retention expiry).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/steps.php';

use Arcp\Client\ARCPClient;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Messages\Control\Resume;
use Arcp\Messages\Execution\JobCheckpoint;
use Arcp\Messages\Execution\JobCompleted;
use Arcp\Messages\Execution\JobProgress;
use Arcp\Messages\Execution\WorkflowStart;

use function Arcp\Samples\Resumability\runStep;

const STEPS = ['plan', 'gather', 'synthesize', 'critique', 'finalize'];

/** Deterministic per-step idempotency key (RFC §6.4). */
function stepKey(string $jobId, string $step, string $salt): string
{
    $h = hash('sha256', $jobId . "\x00" . $step . "\x00" . $salt);
    return "research:{$jobId}:{$step}:" . substr($h, 0, 16);
}

function emitProgress(ARCPClient $client, JobId $jobId, string $step): void
{
    $idx = array_search($step, STEPS, true);
    $pct = (int) (100 * ((int) $idx + 1) / count(STEPS));
    $client->session->transport->send(new Envelope(
        id: MessageId::random(),
        payload: new JobProgress(percent: $pct, message: $step),
        timestamp: new SystemClock()->now(),
        sessionId: $client->session->sessionId,
        jobId: $jobId,
    ));
}

function emitCheckpoint(ARCPClient $client, JobId $jobId, string $step): string
{
    $chk = "chk_{$step}_" . substr((string) $jobId, -6);
    $client->session->transport->send(new Envelope(
        id: MessageId::random(),
        payload: new JobCheckpoint(checkpointId: $chk, state: ['label' => $step]),
        timestamp: new SystemClock()->now(),
        sessionId: $client->session->sessionId,
        jobId: $jobId,
    ));
    return $chk;
}

function executeSteps(
    ARCPClient $client,
    JobId $jobId,
    mixed $request,
    string $startingAt,
    ?string $crashAfter,
): mixed {
    $output = $request;
    $startIdx = (int) array_search($startingAt, STEPS, true);
    foreach (STEPS as $idx => $step) {
        if ($idx < $startIdx) {
            continue;
        }
        $key = stepKey((string) $jobId, $step, var_export($output, true));
        emitProgress($client, $jobId, $step);
        $output = runStep($client, (string) $jobId, $step, [
            'prior' => $output,
            'idempotency_key' => $key,
        ]);
        emitCheckpoint($client, $jobId, $step);
        if ($crashAfter === $step) {
            // The whole point of durable jobs: process death is fine.
            // Runtime kept every envelope; resume picks it up.
            fprintf(
                STDERR,
                '[crash after %s; resume with RESUME_JOB_ID=%s '
                . 'RESUME_CHECKPOINT_ID=chk_%s_%s '
                . "RESUME_AFTER_MSG_ID=<last id from your event log>]\n",
                $step,
                (string) $jobId,
                $step,
                substr((string) $jobId, -6),
            );
            exit(137);
        }
    }
    return $output;
}

function issueResume(ARCPClient $client, JobId $jobId, string $afterMessageId, ?string $checkpointId): ?string
{
    $payload = new Resume(
        afterMessageId: $afterMessageId,
        checkpointId: $checkpointId,
        includeOpenStreams: true,
    );
    $client->session->transport->send(new Envelope(
        id: MessageId::random(),
        payload: $payload,
        timestamp: new SystemClock()->now(),
        sessionId: $client->session->sessionId,
        jobId: $jobId,
    ));

    // Drain replay; capture the last checkpoint label, return when
    // backfill_complete arrives (replay window closed; now live).
    // Real impl uses a dedicated subscribe + Future. Throws DataLoss
    // on retention expiry.
    throw new \RuntimeException('not implemented');
}

function main(): void
{
    /** @var ARCPClient $client */
    $client = elided(); // transport, identity, auth elided

    $rjId = (($v = getenv('RESUME_JOB_ID')) !== false && $v !== '') ? $v : null;
    $rjAfter = (($v = getenv('RESUME_AFTER_MSG_ID')) !== false && $v !== '') ? $v : null;
    $rjCheckpoint = (($v = getenv('RESUME_CHECKPOINT_ID')) !== false && $v !== '') ? $v : null;

    if ($rjId !== null && $rjAfter !== null) {
        $jobId = new JobId($rjId);
        $last = issueResume($client, $jobId, $rjAfter, $rjCheckpoint);
        if ($last === null) {
            echo "already terminal during replay\n";
        } else {
            $nextIdx = (int) array_search($last, STEPS, true) + 1;
            if ($nextIdx >= count(STEPS)) {
                echo "nothing to resume\n";
            } else {
                printf("[resuming at %s]\n", STEPS[$nextIdx]);
                $final = executeSteps($client, $jobId, '<replayed>', STEPS[$nextIdx], null);
                $client->session->transport->send(new Envelope(
                    id: MessageId::random(),
                    payload: new JobCompleted(value: $final),
                    timestamp: new SystemClock()->now(),
                    sessionId: $client->session->sessionId,
                    jobId: $jobId,
                ));
            }
        }
    } else {
        $jobId = JobId::random();
        $request = 'Survey CRDT-based collaborative editing in 2026.';
        $client->session->transport->send(new Envelope(
            id: MessageId::random(),
            payload: new WorkflowStart(payload: ['workflow' => 'research.v1', 'arguments' => ['request' => $request]]),
            timestamp: new SystemClock()->now(),
            sessionId: $client->session->sessionId,
            jobId: $jobId,
        ));
        $crash = (($v = getenv('CRASH_AFTER_STEP')) !== false && $v !== '') ? $v : null;
        $final = executeSteps($client, $jobId, $request, STEPS[0], $crash);
        $client->session->transport->send(new Envelope(
            id: MessageId::random(),
            payload: new JobCompleted(value: $final),
            timestamp: new SystemClock()->now(),
            sessionId: $client->session->sessionId,
            jobId: $jobId,
        ));
        printf("job_id=%s\n%s\n", (string) $jobId, var_export($final, true));
    }

    $client->close();
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
