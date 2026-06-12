<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Arcp\Envelope\Priority;
use Arcp\Errors\BudgetExhaustedException;
use Arcp\Errors\DeadlineExceededException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Ids\JobId;
use Arcp\Ids\LeaseId;
use Arcp\Ids\SessionId;
use Arcp\Ids\StreamId;
use Arcp\Ids\TraceId;
use Arcp\Internal\Runtime\PermissionRequestSpec;
use Arcp\Messages\Artifacts\ArtifactRef;
use Arcp\Messages\Execution\JobHeartbeat;
use Arcp\Messages\Execution\JobProgress;
use Arcp\Messages\Execution\ResultChunk;
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
use Arcp\Messages\Telemetry\LogEvent;
use Arcp\Messages\Telemetry\MetricEvent;

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

    public function emitResultChunk(
        string $resultId,
        string $data,
        bool $more = true,
        string $encoding = 'utf8',
    ): void {
        $job = $this->runtime->jobs->tryGet($this->jobId);
        $seq = $job instanceof Job ? $job->nextResultChunkSeq($resultId) : 0;
        $this->runtime->emit(
            $this->session,
            new ResultChunk($resultId, $seq, $data, $encoding, $more),
            ['job_id' => $this->jobId, 'trace_id' => $this->traceId],
        );
    }

    /**
     * Drain guidance delivered by an `interrupt` for this job, oldest first.
     * Returns null when no interrupt response is pending.
     */
    public function takeInterruptResponse(): ?HumanInputResponse
    {
        return $this->runtime->jobs->tryGet($this->jobId)?->takeInterruptResponse();
    }

    public function reportProgress(int $percent, ?string $message = null): void
    {
        $this->runtime->emit($this->session, new JobProgress($percent, $message), [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function emitLog(string $level, string $message, array $attributes = []): void
    {
        $this->runtime->emit($this->session, new LogEvent($level, $message, $attributes), [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
        ]);
    }

    /** @param array<string, bool|float|int|string> $dims */
    public function emitMetric(string $name, int|float $value, string $unit, array $dims = []): void
    {
        $job = $this->runtime->jobs->tryGet($this->jobId);
        try {
            $remaining = $job?->budget?->consume($name, $value, $unit);
        } catch (BudgetExhaustedException $e) {
            // Surface the consuming sample to observers before unwinding so
            // the metric that exhausted the budget is not lost.
            $dims['budget_remaining'] = '0';
            $this->runtime->emit($this->session, new MetricEvent($name, $value, $unit, $dims), [
                'job_id' => $this->jobId,
                'trace_id' => $this->traceId,
            ]);
            throw $e;
        }
        if ($remaining !== null) {
            $dims['budget_remaining'] = $remaining;
        }
        $this->runtime->emit($this->session, new MetricEvent($name, $value, $unit, $dims), [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
        ]);
    }

    /**
     * Open a `text`/`event`/`log`/`thought` stream and return its id and a
     * helper to push chunks. `binary` is supported as base64 only.
     *
     * @return array{
     *     0: StreamId,
     *     1: \Closure(string|array<string, mixed>|null, ?string=): void,
     *     2: \Closure(?int=): void
     * }
     */
    public function openStream(
        StreamKind $kind,
        ?string $contentType = null,
        ?string $encoding = null,
    ): array {
        $sid = StreamId::random();
        $this->runtime->emit($this->session, new StreamOpen($kind, $contentType, $encoding), [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
            'stream_id' => $sid,
        ]);
        $sequence = 0;
        return [$sid, $this->makeChunkEmitter($sid, $sequence), $this->makeCloser($sid, $sequence)];
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
     * Ask the human a free-form question and return the validated value.
     * RFC §12.1 — runtime moves the job to `blocked` while waiting.
     *
     * @param array<string, mixed> $responseSchema
     * @param array<string, mixed>|null $default
     *
     * @throws \Arcp\Errors\DeadlineExceededException when `$expiresAt`
     *                                                elapses without a response and no `$default` is provided.
     * @throws \Arcp\Errors\CancelledException when `$cancellation` fires.
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
        } catch (DeadlineExceededException $e) {
            if ($default !== null) {
                return new HumanInputResponse($default, 'default', $this->runtime->clock->now());
            }
            $this->runtime->emit($this->session, new HumanInputCancelled('DEADLINE_EXCEEDED'), [
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
     * @throws \Arcp\Errors\DeadlineExceededException when `$expiresAt`
     *                                                elapses before a choice arrives.
     * @throws \Arcp\Errors\CancelledException when `$cancellation` fires.
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
     * @throws \Arcp\Errors\DeadlineExceededException when the request
     *                                                times out before any decision arrives.
     * @throws \Arcp\Errors\CancelledException when `$cancellation` fires.
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
        return $this->runtime->artifacts->put(
            $this->session,
            new ArtifactBlob($mediaType, $bytes, $retentionSeconds),
        );
    }

    /**
     * Emit `job.heartbeat` (RFC §10.3). The runtime expects callers to
     * heartbeat at least every `heartbeat_interval_seconds`; the deadline
     * defaults to twice the interval so a single drop is forgiven.
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
