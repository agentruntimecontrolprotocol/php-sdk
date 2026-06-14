<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Arcp\Envelope\Priority;
use Arcp\Errors\BudgetExhaustedException;
use Arcp\Errors\InvalidRequestException;
use Arcp\Errors\LeaseExpiredException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Errors\TimeoutException;
use Arcp\Ids\JobId;
use Arcp\Ids\LeaseId;
use Arcp\Ids\SessionId;
use Arcp\Ids\StreamId;
use Arcp\Ids\TraceId;
use Arcp\Internal\Runtime\PermissionRequestSpec;
use Arcp\Messages\Artifacts\ArtifactRef;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Execution\JobHeartbeat;
use Arcp\Messages\Execution\ResultChunkEncoding;
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanChoiceResponse;
use Arcp\Messages\Human\HumanInputCancelled;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Messages\Permissions\PermissionDeny;
use Arcp\Messages\Permissions\PermissionGrant;
use Arcp\Messages\Permissions\PermissionRequest;
use Arcp\Messages\Streaming\StreamChunk;
use Arcp\Messages\Streaming\StreamClose;
use Arcp\Messages\Streaming\StreamKind;
use Arcp\Messages\Streaming\StreamOpen;

/**
 * Handle passed to a {@see ToolHandler} to interact with the runtime
 * (progress, streams, human-input, permissions, artifacts). All async
 * methods accept an optional {@see Cancellation} that participates in
 * the per-job cancellation tree.
 */
final class JobContext
{
    use JobCredentialControls;

    /** @internal The runtime sets this during job dispatch. */
    public ?DeferredCancellation $cancellation = null;

    public function __construct(
        public readonly ARCPRuntime $runtime,
        public readonly Session $session,
        public readonly JobId $jobId,
        public readonly SessionId $sessionId,
        public readonly ?TraceId $traceId = null,
    ) {
    }

    /**
     * Stream a §8.4 result fragment as a `job.event` of kind
     * `result_chunk`. The runtime mints the stable `result_id` on the
     * first chunk and returns it; the terminal `job.result` references it
     * (`final_status` + `result_id`) and the job MUST NOT also return an
     * inline result.
     *
     * Retransmission: passing an already-emitted `$seq` with
     * byte-identical fields re-sends the chunk (receivers dedupe);
     * divergent payloads for the same `seq` are rejected.
     *
     * @return string the stable §8.4 `result_id` for this job's stream
     */
    public function emitResultChunk(
        string $data,
        bool $more = true,
        ResultChunkEncoding $encoding = ResultChunkEncoding::Utf8,
        ?int $seq = null,
    ): string {
        $job = $this->runtime->jobs->tryGet($this->jobId)
            ?? throw new InvalidRequestException('job no longer tracked');
        $resultId = $job->streamedResultId ??= 'res_' . bin2hex(random_bytes(12));
        $seq ??= $job->nextResultChunkSeq();
        $fingerprint = hash('sha256', $data . "\0" . $encoding->value . "\0" . ($more ? '1' : '0'));
        $prior = $job->resultChunkHashes[$seq] ?? null;
        if ($prior !== null && $prior !== $fingerprint) {
            // §8.4: a divergent duplicate would corrupt the assembled
            // result; only byte-identical retransmission is tolerated.
            throw new InvalidRequestException(
                'result_chunk retransmission diverges from original',
                ['result_id' => $resultId, 'chunk_seq' => $seq],
            );
        }
        if ($prior === null) {
            $job->resultChunkHashes[$seq] = $fingerprint;
            $decoded = $encoding === ResultChunkEncoding::Base64
                ? base64_decode($data, strict: true)
                : $data;
            if ($decoded === false) {
                throw new InvalidRequestException('result_chunk data is not valid base64');
            }
            $job->streamedResultBytes += \strlen($decoded);
            if (!$more) {
                $job->resultStreamClosed = true;
            }
        }
        $this->emitJobEvent('result_chunk', [
            'result_id' => $resultId,
            'chunk_seq' => $seq,
            'data' => $data,
            'encoding' => $encoding->value,
            'more' => $more,
        ]);
        return $resultId;
    }

    /**
     * §9.5: assert the job's effective lease has not expired before an
     * authority-bearing operation. Throws {@see LeaseExpiredException}
     * (code `LEASE_EXPIRED`, `retryable: false`) at or after the lease's
     * `expires_at`; the handler fiber then unwinds and the runtime
     * terminates the job with `final_status: "error"`. Agents SHOULD call
     * this before performing their own authority-bearing work (fs/net/tool
     * operations); the runtime calls it internally before the operations
     * it mediates (artifacts, permission requests).
     */
    public function enforceLease(): void
    {
        $lease = $this->runtime->jobs->tryGet($this->jobId)?->lease;
        if ($lease instanceof LeaseGranted && $lease->expiresAt <= $this->runtime->clock->now()) {
            throw new LeaseExpiredException($lease->leaseId, $lease->expiresAt);
        }
    }

    /**
     * Drain guidance delivered by an `interrupt` for this job, oldest first.
     * Returns null when no interrupt response is pending.
     */
    public function takeInterruptResponse(): ?HumanInputResponse
    {
        return $this->runtime->jobs->tryGet($this->jobId)?->takeInterruptResponse();
    }

    /**
     * Emit a §8.2.1 `progress` job event: `{current, total?, units?,
     * message?}`. `current` MUST be non-negative; when `total` is present
     * `current` must not exceed it. Advisory only — the protocol does
     * not act on progress events.
     */
    public function reportProgress(
        int $current,
        ?int $total = null,
        ?string $units = null,
        ?string $message = null,
    ): void {
        if ($current < 0) {
            throw new InvalidRequestException('progress current must be non-negative');
        }
        if ($total !== null && $total < 0) {
            throw new InvalidRequestException('progress total must be non-negative');
        }
        if ($total !== null && $current > $total) {
            throw new InvalidRequestException('progress current must not exceed total');
        }
        $body = ['current' => $current];
        if ($total !== null) {
            $body['total'] = $total;
        }
        if ($units !== null) {
            $body['units'] = $units;
        }
        if ($message !== null) {
            $body['message'] = $message;
        }
        $this->emitJobEvent('progress', $body);
    }

    /**
     * Emit a §8.2 `log` job event: `{level, message}` (plus optional
     * structured attributes).
     *
     * @param array<string, mixed> $attributes
     */
    public function emitLog(string $level, string $message, array $attributes = []): void
    {
        $body = ['level' => $level, 'message' => $message];
        if ($attributes !== []) {
            $body['attributes'] = $attributes;
        }
        $this->emitJobEvent('log', $body);
    }

    /**
     * Emit a §8.2 `metric` job event: `{name, value, unit?, dimensions?}`.
     * `cost.*` samples decrement the job's §9.6 budget counters.
     *
     * @param array<string, bool|float|int|string> $dims
     */
    public function emitMetric(string $name, int|float $value, string $unit, array $dims = []): void
    {
        $job = $this->runtime->jobs->tryGet($this->jobId);
        try {
            $remaining = $job?->budget?->consume($name, $value, $unit);
        } catch (BudgetExhaustedException $e) {
            // Surface the consuming sample to observers before unwinding so
            // the metric that exhausted the budget is not lost.
            $dims['budget_remaining'] = '0';
            $this->emitJobEvent('metric', $this->metricBody($name, $value, $unit, $dims));
            throw $e;
        }
        if ($remaining !== null) {
            $dims['budget_remaining'] = $remaining;
        }
        $this->emitJobEvent('metric', $this->metricBody($name, $value, $unit, $dims));
    }

    /**
     * @param array<string, bool|float|int|string> $dims
     *
     * @return array<string, mixed>
     */
    private function metricBody(string $name, int|float $value, string $unit, array $dims): array
    {
        $body = ['name' => $name, 'value' => $value, 'unit' => $unit];
        if ($dims !== []) {
            $body['dimensions'] = $dims;
        }
        return $body;
    }

    /**
     * Emit a §8.1 `job.event` envelope with this job's id and trace.
     *
     * @param array<string, mixed> $body
     */
    private function emitJobEvent(string $kind, array $body): void
    {
        $this->runtime->emit(
            $this->session,
            new JobEvent($kind, $this->runtime->clock->now(), $body),
            ['job_id' => $this->jobId, 'trace_id' => $this->traceId],
        );
    }

    /**
     * Open a `text`/`event`/`log`/`thought` stream and return a typed
     * {@see StreamHandle} exposing `id`, `push()`, and `close()`. `binary`
     * is supported as base64 only.
     */
    public function openStream(
        StreamKind $kind,
        ?string $contentType = null,
        ?string $encoding = null,
    ): StreamHandle {
        $sid = StreamId::random();
        $this->runtime->emit($this->session, new StreamOpen($kind, $contentType, $encoding), [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
            'stream_id' => $sid,
        ]);
        $sequence = 0;
        return new StreamHandle(
            $sid,
            $this->makeChunkEmitter($sid, $sequence),
            $this->makeCloser($sid, $sequence),
        );
    }

    /**
     * @return \Closure(string|array<string, mixed>|null, ?string=): void
     */
    private function makeChunkEmitter(StreamId $sid, int &$sequence): \Closure
    {
        return function (
            string|array|null $body,
            ?string $extraContentType = null,
        ) use ($sid, &$sequence): void {
            $payload = match (true) {
                \is_string($body) => new StreamChunk(
                    sequence: $sequence++,
                    contentType: $extraContentType,
                    content: $body,
                ),
                \is_array($body) => new StreamChunk(
                    sequence: $sequence++,
                    data: $body,
                    contentType: $extraContentType,
                ),
                default => new StreamChunk(sequence: $sequence++),
            };
            $this->runtime->emit($this->session, $payload, [
                'job_id' => $this->jobId,
                'trace_id' => $this->traceId,
                'stream_id' => $sid,
            ]);
        };
    }

    /** @return \Closure(?int=): void */
    private function makeCloser(StreamId $sid, int &$sequence): \Closure
    {
        return function (?int $totalChunks = null) use ($sid, &$sequence): void {
            $this->runtime->emit($this->session, new StreamClose($totalChunks ?? $sequence), [
                'job_id' => $this->jobId,
                'trace_id' => $this->traceId,
                'stream_id' => $sid,
            ]);
        };
    }

    /**
     * Ask the human a free-form question and return the validated value
     * (RFC §12.1). Blocks the calling fiber until a response, the deadline,
     * or cancellation; it does not mutate the job's tracked state.
     *
     * @param array<string, mixed> $responseSchema
     * @param array<string, mixed>|null $default
     *
     * @throws \Arcp\Errors\TimeoutException when `$expiresAt`
     *                                       elapses without a response and no `$default` is provided.
     * @throws \Amp\CancelledException when `$cancellation` fires.
     *
     * @size-check-suppress public BC; mirrors RFC §12.1 human.input.request.
     */
    public function requestHumanInput(
        string $prompt,
        array $responseSchema,
        \DateTimeImmutable $expiresAt,
        ?array $default = null,
        ?Cancellation $cancellation = null,
    ): HumanInputResponse {
        $req = new HumanInputRequest($prompt, $responseSchema, $expiresAt, $default);
        $msgId = $this->runtime->emit($this->session, $req, [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
            'priority' => Priority::High,
        ]);
        $deadline = max(
            0.001,
            $expiresAt->getTimestamp() - $this->runtime->clock->now()->getTimestamp(),
        );
        try {
            /** @var HumanInputResponse $response */
            $response = $this->runtime->pending->awaitResponse($msgId, $deadline, $cancellation);
            return $response;
        } catch (TimeoutException $e) {
            if ($default !== null) {
                return new HumanInputResponse($default, 'default', $this->runtime->clock->now());
            }
            $this->runtime->emit($this->session, new HumanInputCancelled('TIMEOUT'), [
                'job_id' => $this->jobId,
                'trace_id' => $this->traceId,
                'correlation_id' => $msgId,
            ]);
            throw $e;
        }
    }

    /**
     * @param list<array{id: string, label: string}> $options
     *
     * @throws \Arcp\Errors\TimeoutException when `$expiresAt`
     *                                       elapses before a choice arrives.
     * @throws \Amp\CancelledException when `$cancellation` fires.
     *
     * @size-check-suppress public BC; mirrors RFC §12.1 human.choice.request.
     */
    public function requestHumanChoice(
        string $prompt,
        array $options,
        \DateTimeImmutable $expiresAt,
        ?Cancellation $cancellation = null,
    ): HumanChoiceResponse {
        $req = new HumanChoiceRequest($prompt, $options, $expiresAt);
        $msgId = $this->runtime->emit($this->session, $req, [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
            'priority' => Priority::High,
        ]);
        $deadline = max(
            0.001,
            $expiresAt->getTimestamp() - $this->runtime->clock->now()->getTimestamp(),
        );
        /** @var HumanChoiceResponse $response */
        $response = $this->runtime->pending->awaitResponse($msgId, $deadline, $cancellation);
        return $response;
    }

    /**
     * @throws \Arcp\Errors\PermissionDeniedException when the client
     *                                                denies the permission request.
     * @throws \Arcp\Errors\TimeoutException when the request
     *                                       times out before any decision arrives.
     * @throws \Amp\CancelledException when `$cancellation` fires.
     *
     * @size-check-suppress public BC; protocol-level permission request fields.
     */
    public function requestPermission(
        string $permission,
        string $resource,
        string $operation,
        string $reason = '',
        int $requestedLeaseSeconds = 300,
        ?Cancellation $cancellation = null,
    ): LeaseId {
        // §9.5: acquiring new authority is itself authority-bearing.
        $this->enforceLease();
        $spec = new PermissionRequestSpec(
            $permission,
            $resource,
            $operation,
            $reason,
            $requestedLeaseSeconds,
        );
        $response = $this->awaitPermissionDecision($spec, $cancellation);
        return $this->registerGrantedLease($spec, $response);
    }

    private function awaitPermissionDecision(
        PermissionRequestSpec $spec,
        ?Cancellation $cancellation,
    ): PermissionGrant {
        $req = new PermissionRequest(
            $spec->permission,
            $spec->resource,
            $spec->operation,
            $spec->reason,
            $spec->requestedLeaseSeconds,
        );
        $msgId = $this->runtime->emit($this->session, $req, [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
            'priority' => Priority::Critical,
        ]);
        $response = $this->runtime->pending->awaitResponse(
            $msgId,
            (float) $spec->requestedLeaseSeconds + 60.0,
            $cancellation,
        );
        if ($response instanceof PermissionDeny) {
            throw new PermissionDeniedException($spec->permission, $spec->resource);
        }
        if (!$response instanceof PermissionGrant) {
            throw new PermissionDeniedException(
                $spec->permission,
                $spec->resource,
                'unexpected response type ' . $response::class,
            );
        }
        return $response;
    }

    private function registerGrantedLease(
        PermissionRequestSpec $spec,
        PermissionGrant $response,
    ): LeaseId {
        $leaseId = LeaseId::random();
        $leaseSeconds = $response->leaseSeconds ?? $spec->requestedLeaseSeconds;
        $expiresAt = $this->runtime->clock->now()->modify('+' . $leaseSeconds . ' seconds');
        $granted = new LeaseGranted(
            leaseId: $leaseId,
            permission: $spec->permission,
            resource: $spec->resource,
            operation: $spec->operation,
            expiresAt: $expiresAt,
        );
        $this->runtime->leases->register($granted, $this->session->sessionId);
        $this->runtime->emit($this->session, $granted, [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
        ]);
        return $leaseId;
    }

    public function putArtifact(
        string $mediaType,
        string $bytes,
        ?int $retentionSeconds = null,
    ): ArtifactRef {
        // §9.5: persisting an artifact is an authority-bearing (fs.write)
        // operation; reject it once the job's lease has expired.
        $this->enforceLease();
        return $this->runtime->artifacts->put(
            $this->session,
            new ArtifactBlob($mediaType, $bytes, $retentionSeconds),
        );
    }

    /**
     * Emit a `job.heartbeat` event (RFC §10.3) carrying a monotonically
     * increasing sequence, the caller-supplied `$deadlineMs` liveness hint,
     * and the reported `$state`. This method does not itself enforce any
     * interval; it is a no-op if the job is no longer tracked.
     */
    public function heartbeat(int $deadlineMs = 60000, string $state = 'running'): void
    {
        $job = $this->runtime->jobs->tryGet($this->jobId);
        if (!$job instanceof Job) {
            return;
        }
        $sequence = ++$job->heartbeatSequence;
        $this->runtime->emit(
            $this->session,
            new JobHeartbeat($sequence, $deadlineMs, $state),
            ['job_id' => $this->jobId, 'trace_id' => $this->traceId],
        );
    }
}
