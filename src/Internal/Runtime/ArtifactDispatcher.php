<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Errors\ARCPException;
use Arcp\Messages\Artifacts\ArtifactFetch;
use Arcp\Messages\Artifacts\ArtifactPut;
use Arcp\Messages\Artifacts\ArtifactRelease;
use Arcp\Messages\Control\Ack;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\ArtifactBlob;
use Arcp\Runtime\Session;

/**
 * Handles `artifact.put`, `artifact.fetch`, and `artifact.release`
 * envelopes (RFC §16).
 *
 * @internal
 */
final readonly class ArtifactDispatcher
{
    public function __construct(
        private ARCPRuntime $runtime,
        private LifecycleHandler $lifecycle,
    ) {
    }

    public function put(Session $session, Envelope $env, ArtifactPut $msg): void
    {
        $bytes = base64_decode($msg->data, strict: true);
        if ($bytes === false) {
            $this->lifecycle->nack(
                $session,
                $env,
                'INVALID_ARGUMENT',
                'artifact.put data must be base64',
            );
            return;
        }
        if ($msg->sha256 !== null) {
            $normalized = strtolower(trim($msg->sha256));
            if (preg_match('/^[0-9a-f]{64}$/', $normalized) !== 1) {
                $this->lifecycle->nack(
                    $session,
                    $env,
                    'INVALID_ARGUMENT',
                    'artifact.put sha256 must be 64 lowercase hex chars',
                );
                return;
            }
            $computed = hash('sha256', $bytes);
            if (!hash_equals($computed, $normalized)) {
                $this->lifecycle->nack(
                    $session,
                    $env,
                    'INVALID_ARGUMENT',
                    'artifact.put sha256 does not match payload',
                );
                return;
            }
        }
        $ref = $this->runtime->artifacts->put(
            $session,
            new ArtifactBlob($msg->mediaType, $bytes, $msg->retentionSeconds),
        );
        $this->runtime->emit($session, $ref, ['correlation_id' => $env->id]);
    }

    public function fetch(Session $session, Envelope $env, ArtifactFetch $msg): void
    {
        try {
            $bytes = $this->runtime->artifacts->fetch($msg->artifactId, $session);
            $mediaType = $this->runtime->artifacts->ref($msg->artifactId, $session)->mediaType;
        } catch (ARCPException $e) {
            $this->lifecycle->nack($session, $env, $e->code()->value, $e->getMessage());
            return;
        }
        $put = new ArtifactPut(
            mediaType: $mediaType,
            data: base64_encode($bytes),
        );
        $this->runtime->emit($session, $put, ['correlation_id' => $env->id]);
    }

    public function release(Session $session, Envelope $env, ArtifactRelease $msg): void
    {
        try {
            $ok = $this->runtime->artifacts->release($msg->artifactId, $session);
        } catch (ARCPException $e) {
            $this->lifecycle->nack($session, $env, $e->code()->value, $e->getMessage());
            return;
        }
        $this->runtime->emit($session, new Ack($ok ? 'released' : 'unknown'), [
            'correlation_id' => $env->id,
        ]);
    }
}
