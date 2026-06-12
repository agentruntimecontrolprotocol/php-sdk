<?php

declare(strict_types=1);

/*
 * handoff — cheap-tier first; escalate to deep tier via agent.handoff.
 *
 * RFC §14 (handoff), §16 (artifacts), §8.3 (runtime fingerprint pinning).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/cheap.php';

use Arcp\Client\ARCPClient;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Ids\MessageId;
use Arcp\Ids\TraceId;
use Arcp\Messages\Artifacts\ArtifactRef;
use Arcp\Messages\Execution\AgentHandoff;

use function Arcp\Samples\Handoff\attempt;

const CONFIDENCE_THRESHOLD = 0.65;
const CHEAP_URL = 'wss://haiku-pool.tier1.internal';
const DEEP_URL = 'wss://opus-pool.tier3.internal';
const DEEP_KIND = 'arcp-opus-pool';
const DEEP_FINGERPRINT = 'sha256:0a37bf7d61cca21f00...';

/**
 * @param array<string, mixed> $transcript
 */
function packageContext(ARCPClient $client, array $transcript): ArtifactRef
{
    $body = json_encode($transcript, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    // putArtifact awaits the artifact.ref response; returns the typed value.
    return $client->putArtifact('application/json', $body);
}

function emitHandoff(ARCPClient $client, ArtifactRef $ref, TraceId $traceId): void
{
    // §14: hand the session to a different runtime. Spec gestures at
    // shared_memory_ref; we use it explicitly so the deep tier knows
    // where the transcript lives.
    $payload = new AgentHandoff([
        'target_runtime' => ['url' => DEEP_URL, 'kind' => DEEP_KIND, 'fingerprint' => DEEP_FINGERPRINT],
        'shared_memory_ref' => ['artifact_id' => (string) $ref->artifactId],
    ]);
    $client->session->transport->send(new Envelope(
        id: MessageId::random(),
        payload: $payload,
        timestamp: new SystemClock()->now(),
        sessionId: $client->session->sessionId,
        traceId: $traceId,
    ));
}

function main(): void
{
    /** @var ARCPClient $cheap */
    $cheap = elided(); // transport=WebSocketTransport(CHEAP_URL), pinned

    // Pin runtime kind + fingerprint (RFC §8.3); refuse on mismatch.
    $peer = $cheap->session->peerInfo;
    if ($peer === null || $peer->name !== 'arcp-haiku-pool') {
        throw new UnauthenticatedException('cheap kind mismatch');
    }

    $request = 'what does CRDT stand for?';
    $traceId = TraceId::random();

    [$answer, $confidence] = attempt($request);
    if ($confidence >= CONFIDENCE_THRESHOLD) {
        echo $answer, "\n";
    } else {
        $artifact = packageContext($cheap, [
            'user_request' => $request,
            'transcript' => [
                ['role' => 'user', 'content' => $request],
                ['role' => 'assistant', 'content' => $answer],
            ],
            'cheap_confidence' => $confidence,
        ]);
        emitHandoff($cheap, $artifact, $traceId);
        printf("[handed off to %s trace_id=%s]\n", DEEP_KIND, (string) $traceId);
    }

    $cheap->close();
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
