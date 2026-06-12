<?php

declare(strict_types=1);

/*
 * reasoning-streams — primary emits reasoning as a `kind: thought`
 * stream; mirror peer subscribes, runs a critic, delegates critiques
 * back via `agent.delegate`.
 *
 * RFC §11.4 (kind: thought), §13 (subscriptions), §14 (delegate),
 * §17.3.1 (token budget).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/agents.php';

use function Amp\async;

use Arcp\Client\ARCPClient;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\StreamId;
use Arcp\Messages\Execution\AgentDelegate;
use Arcp\Messages\Streaming\StreamChunk;
use Arcp\Messages\Streaming\StreamKind;
use Arcp\Messages\Streaming\StreamOpen;

use function Arcp\Samples\ReasoningStreams\critiqueThought;
use function Arcp\Samples\ReasoningStreams\primaryStep;

const MAX_DEPTH = 3;
const TOKEN_BUDGET = 8_000;

// Primary side -----------------------------------------------------------

/**
 * @param \SplQueue<array<string, mixed>> $inboundCritiques
 */
function runPrimary(ARCPClient $client, string $request, \SplQueue $inboundCritiques): string
{
    $clock = new SystemClock();
    $streamId = StreamId::random();
    $client->session->transport->send(new Envelope(
        id: MessageId::random(),
        payload: new StreamOpen(StreamKind::Thought),
        timestamp: $clock->now(),
        sessionId: $client->session->sessionId,
        streamId: $streamId,
    ));

    $last = null;
    $answer = '';
    for ($step = 0; $step < MAX_DEPTH; $step++) {
        $answer = primaryStep($request, $last);
        $client->session->transport->send(new Envelope(
            id: MessageId::random(),
            payload: new StreamChunk(
                sequence: $step,
                role: 'assistant_thought',
                content: $answer,
            ),
            timestamp: $clock->now(),
            sessionId: $client->session->sessionId,
            streamId: $streamId,
        ));
        // Wait briefly for inbound critique; bail if 'halt'.
        $last = $inboundCritiques->isEmpty() ? null : $inboundCritiques->dequeue();
        if (is_array($last) && ($last['severity'] ?? null) === 'halt') {
            break;
        }
    }
    return $answer;
}

// Mirror side (a peer runtime — both reads thought stream AND delegates
// critique events back) -------------------------------------------------

function runMirror(ARCPClient $mirror, JobId $targetJobId): void
{
    $spent = 0;
    // §7.6: attach to the primary's reasoning job; thought chunks arrive
    // as that job's envelopes.
    $mirror->subscribe(
        $targetJobId,
        function (Envelope $env) use ($mirror, &$spent, $targetJobId): void {
            $chunk = $env->payload;
            if (!$chunk instanceof StreamChunk) {
                return;
            }
            if ($chunk->role !== 'assistant_thought') {
                return;
            }
            if ($spent >= TOKEN_BUDGET) {
                $mirror->unsubscribe($targetJobId);
                return;
            }
            [$severity, $summary, $suggestion, $consumed] = critiqueThought((string) $chunk->content);
            $spent += $consumed;

            // Delegate back as a namespaced extension event.
            $mirror->session->transport->send(new Envelope(
                id: MessageId::random(),
                payload: new AgentDelegate([
                    'target' => 'primary',
                    'task' => 'consume_critique',
                    'context' => [
                        'critique' => [
                            'target_thought_sequence' => $chunk->sequence,
                            'severity' => $severity,
                            'summary' => $summary,
                            'suggestion' => $suggestion,
                            'consumed_tokens' => $consumed,
                        ],
                    ],
                ]),
                timestamp: new SystemClock()->now(),
                sessionId: $mirror->session->sessionId,
            ));
        },
    );
}

function main(): void
{
    /** @var ARCPClient $primary */
    $primary = elided(); // transport, identity, auth elided
    /** @var ARCPClient $mirror */
    $mirror = elided();

    /** @var \SplQueue<array<string, mixed>> $inbound */
    $inbound = new \SplQueue();

    // The primary's reasoning runs as a job; both peers address it by id.
    $primaryJob = new JobId('job_reasoning');

    // Primary attaches to its own job to observe inbound delegate
    // envelopes routed onto the job's stream.
    $primary->subscribe($primaryJob, static function (Envelope $env) use ($inbound): void {
        $msg = $env->payload;
        if (!$msg instanceof AgentDelegate) {
            return;
        }
        $ctx = $msg->payload['context'] ?? null;
        $critique = is_array($ctx) ? ($ctx['critique'] ?? null) : null;
        if (is_array($critique)) {
            /** @var array<string, mixed> $critique */
            $inbound->enqueue($critique);
        }
    });

    async(static fn () => runMirror($mirror, $primaryJob));

    $answer = runPrimary($primary, 'Argue both sides: serializable vs snapshot iso?', $inbound);
    echo $answer, "\n";

    $primary->close();
    $mirror->close();
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
