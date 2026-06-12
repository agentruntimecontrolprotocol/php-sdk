<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use function Amp\async;

use Amp\CancelledException;
use Arcp\Envelope\Envelope;
use Arcp\Errors\AgentVersionNotAvailableException;
use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\IdempotencyKey;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobError;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Execution\JobResult;
use Arcp\Messages\Execution\JobSubmit;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Runtime\AgentRef;
use Arcp\Runtime\CostBudget;
use Arcp\Runtime\Credentials\Credential;
use Arcp\Runtime\Job;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\JobState;
use Arcp\Runtime\ResolvedTool;
use Arcp\Runtime\Session;
use Arcp\Runtime\ToolHandler;
use Arcp\Store\IdempotencyRecord;
use Revolt\EventLoop;

/**
 * Drives the `job.submit` lifecycle (§7.1): agent resolution,
 * idempotency replays, lease/credential issuance, `job.accepted`, the
 * handler fiber, and the terminal `job.result` / `job.error` emissions
 * (§7.3, §8.4, §12).
 *
 * @internal
 */
final readonly class JobSubmitHandler
{
    /** @param \Closure(AgentRef): ?ResolvedTool $resolveTool */
    public function __construct(
        private \Arcp\Runtime\ARCPRuntime $runtime,
        private \Closure $resolveTool,
        private CredentialLifecycle $credentials,
    ) {
    }

    public function handle(Session $session, Envelope $env, JobSubmit $msg): void
    {
        try {
            $resolved = ($this->resolveTool)(AgentRef::parse($msg->agent));
        } catch (AgentVersionNotAvailableException $e) {
            $this->emitSubmitError($session, $env, ErrorPayload::fromException($e));
            return;
        }
        if (!$resolved instanceof ResolvedTool) {
            // §12: an unregistered agent is AGENT_NOT_AVAILABLE (#150).
            $this->emitSubmitError($session, $env, new ErrorPayload(
                'AGENT_NOT_AVAILABLE',
                \sprintf('unknown agent: %s', $msg->agent),
                null,
                ['agent' => $msg->agent],
            ));
            return;
        }
        if ($this->handledByIdempotencyReplay($session, $env, $msg)) {
            return;
        }
        $leaseArguments = $this->leaseArguments($msg);
        try {
            $lease = $this->credentials->leaseFromArguments($leaseArguments, $resolved, $session);
        } catch (ARCPException $e) {
            // Lease resolution can fail with PERMISSION_DENIED (scope/owner),
            // LEASE_SUBSET_VIOLATION (widening overlay, §9.4), or another
            // lease error; surface any of them as a correlated job.error.
            $this->emitSubmitError($session, $env, ErrorPayload::fromException($e));
            return;
        }
        $job = $this->runtime->jobs->start(
            $session,
            $env,
            $resolved->name,
            $resolved->version,
            $lease instanceof LeaseGranted
                ? $lease->costBudget?->snapshot()
                : CostBudget::fromInvocationArguments($leaseArguments),
            $lease,
        );
        $credentials = $this->credentials->issue($session, $env, $job, $lease);
        if ($credentials === null) {
            return;
        }
        $this->emitJobAccepted($session, $env, $job, $msg, $lease, $credentials);
        $this->runtime->jobs->transition($job, JobState::Running);
        $this->runtime->emit($session, new JobEvent(
            'status',
            $this->runtime->clock->now(),
            ['phase' => 'running'],
        ), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->armMaxRuntime($job, $msg);
        $job->future = async(function () use ($session, $env, $msg, $job, $resolved): void {
            $this->runHandler(new SubmitJobContextSpec($session, $env, $msg, $job), $resolved->handler);
        });
    }

    /**
     * Merge the §7.1 `lease_request` / `lease_constraints` payload fields
     * into the legacy in-input `lease` block consumed by the lease and
     * budget extractors, so both submission styles resolve identically.
     *
     * @return array<string, mixed>
     */
    private function leaseArguments(JobSubmit $msg): array
    {
        $arguments = $msg->input;
        $leaseBlock = [];
        if (\is_array($arguments['lease'] ?? null)) {
            /** @var array<string, mixed> $leaseBlock */
            $leaseBlock = $arguments['lease'];
        }
        if ($msg->leaseRequest !== null) {
            $leaseBlock = [...$leaseBlock, ...$msg->leaseRequest];
        }
        if ($msg->leaseConstraints !== null) {
            $leaseBlock['lease_constraints'] = $msg->leaseConstraints;
        }
        if ($leaseBlock !== []) {
            $arguments['lease'] = $leaseBlock;
        }
        return $arguments;
    }

    private function emitSubmitError(Session $session, Envelope $env, ErrorPayload $payload): void
    {
        $this->runtime->emit($session, new JobError(JobError::ERROR, $payload), [
            'correlation_id' => $env->id,
            'trace_id' => $env->traceId,
        ]);
    }

    /**
     * The §7.2 idempotency key: the spec carries it in the job.submit
     * payload; the envelope-level key is honored for compatibility.
     */
    private function idempotencyKey(Envelope $env, JobSubmit $msg): ?IdempotencyKey
    {
        if ($env->idempotencyKey instanceof IdempotencyKey) {
            return $env->idempotencyKey;
        }
        return $msg->idempotencyKey !== null && $msg->idempotencyKey !== ''
            ? new IdempotencyKey($msg->idempotencyKey)
            : null;
    }

    /**
     * Logical idempotency replay (§7.2). Returns true when the request has
     * already been processed and the runtime has re-emitted the original
     * terminal correlated response for the duplicate.
     */
    private function handledByIdempotencyReplay(Session $session, Envelope $env, JobSubmit $msg): bool
    {
        $key = $this->idempotencyKey($env, $msg);
        $principal = $session->principal;
        if (!$key instanceof IdempotencyKey || $principal === null) {
            return false;
        }
        $prior = $this->runtime->eventLog->lookupIdempotent($principal, (string) $key);
        if ($prior === null) {
            return false;
        }
        $original = $this->runtime->eventLog->findByMessageId($prior);
        $hints = [
            'correlation_id' => $env->id,
            'trace_id' => $env->traceId,
        ];
        if ($original instanceof Envelope) {
            if ($original->jobId !== null) {
                $hints['job_id'] = $original->jobId;
            }
            $this->runtime->emit($session, $original->payload, $hints);
        } else {
            // Fallback: if the original outcome envelope is no longer in
            // the log (e.g. retention purged it), fail the duplicate so
            // synchronous callers stop waiting (§7.2: the original
            // job.accepted payload can no longer be reproduced).
            $this->runtime->emit($session, new Nack(new ErrorPayload(
                'INTERNAL_ERROR',
                'idempotent outcome no longer available for replay',
            )), $hints);
        }
        return true;
    }

    /**
     * @param list<Credential> $credentials
     */
    private function emitJobAccepted(
        Session $session,
        Envelope $env,
        Job $job,
        JobSubmit $msg,
        ?LeaseGranted $lease,
        array $credentials,
    ): void {
        // job.accepted is keyed on job_id; only the terminal
        // job.result/job.error envelopes carry correlation_id, so
        // synchronous invokeTool() callers see exactly one resolution.
        $payloadCredentials = $credentials === []
            ? null
            : array_map(fn (Credential $cred): array => $cred->toArray(), $credentials);
        $this->runtime->emit($session, new JobAccepted(
            jobId: $job->id,
            agent: $job->toolRef(),
            lease: $lease?->toArray(),
            leaseConstraints: $msg->leaseConstraints,
            budget: $this->budgetCounters($job),
            credentials: $payloadCredentials,
            acceptedAt: $this->runtime->clock->now(),
            traceId: $env->traceId,
        ), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
    }

    /**
     * Initial §9.6 budget counters for the job.accepted payload, when a
     * cost budget is attached.
     *
     * @return array<string, float>|null
     */
    private function budgetCounters(Job $job): ?array
    {
        $remaining = $job->budget?->remaining();
        if ($remaining === null || $remaining === []) {
            return null;
        }
        $out = [];
        foreach ($remaining as $currency => $amount) {
            $out[$currency] = (float) $amount;
        }
        return $out;
    }

    /** §7.1: enforce `max_runtime_sec` by cancelling into TIMEOUT (§7.3). */
    private function armMaxRuntime(Job $job, JobSubmit $msg): void
    {
        if ($msg->maxRuntimeSec === null) {
            return;
        }
        $job->runtimeTimerId = EventLoop::delay(
            (float) $msg->maxRuntimeSec,
            static function () use ($job): void {
                if ($job->state->isTerminal()) {
                    return;
                }
                $job->timedOut = true;
                $job->cancellation->cancel(
                    new CancelledException(new \RuntimeException('max_runtime_sec exceeded')),
                );
            },
        );
    }

    private function runHandler(SubmitJobContextSpec $spec, ToolHandler $handler): void
    {
        $session = $spec->session;
        $env = $spec->env;
        $job = $spec->job;
        $sid = $session->sessionId ?? throw new InvalidRequestException('session has no id');
        $ctx = new JobContext($this->runtime, $session, $job->id, $sid, $env->traceId);
        $ctx->cancellation = $job->cancellation;
        try {
            $value = $handler->invoke(
                $spec->msg->input,
                $ctx,
                $job->cancellation->getCancellation(),
            );
            $this->completeJob($spec, $value);
        } catch (CancelledException) {
            $job->timedOut ? $this->timeOutJob($spec) : $this->cancelJob($spec);
        } catch (ARCPException $e) {
            $this->failJob($spec, ErrorPayload::fromException($e));
        } catch (\Throwable $e) {
            // §12: INTERNAL_ERROR is always retryable — flag it explicitly
            // so the wire shape is correct and the idempotency key is not
            // consumed.
            $this->failJob($spec, new ErrorPayload('INTERNAL_ERROR', $e->getMessage(), true));
        } finally {
            if ($job->runtimeTimerId !== null) {
                EventLoop::cancel($job->runtimeTimerId);
                $job->runtimeTimerId = null;
            }
        }
    }

    private function completeJob(SubmitJobContextSpec $spec, mixed $value): void
    {
        $session = $spec->session;
        $env = $spec->env;
        $job = $spec->job;
        if ($job->streamedResultId !== null) {
            // §8.4: once a result_chunk was emitted the terminal job.result
            // MUST carry result_id — mixing in an inline result is rejected.
            if ($value !== null) {
                $this->failJob($spec, new ErrorPayload(
                    'INVALID_REQUEST',
                    'job mixed inline result and result_chunk (§8.4)',
                    false,
                ));
                return;
            }
            if (!$job->resultStreamClosed) {
                $this->failJob($spec, new ErrorPayload(
                    'INVALID_REQUEST',
                    'result_chunk stream not terminated: final chunk must carry more=false (§8.4)',
                    false,
                ));
                return;
            }
        }
        $this->runtime->jobs->transition($job, JobState::Success);
        // §7.3/§8.4: a single terminal job.result resolves the synchronous
        // submitter via correlation_id. It carries either the inline result
        // or the §8.4 result_id + result_size of the streamed result.
        $result = $job->streamedResultId !== null
            ? new JobResult(
                finalStatus: JobResult::SUCCESS,
                resultId: $job->streamedResultId,
                resultSize: $job->streamedResultBytes,
            )
            : new JobResult(finalStatus: JobResult::SUCCESS, result: $value);
        $outcomeId = $this->runtime->emit($session, $result, [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->credentials->revoke($job);
        $this->rememberIdempotent($session, $env, $spec->msg, (string) $outcomeId);
    }

    private function rememberIdempotent(
        Session $session,
        Envelope $env,
        JobSubmit $msg,
        string $outcomeMessageId,
    ): void {
        $key = $this->idempotencyKey($env, $msg);
        $principal = $session->principal;
        if (!$key instanceof IdempotencyKey || $principal === null) {
            return;
        }
        $this->runtime->eventLog->rememberIdempotent(new IdempotencyRecord(
            $principal,
            (string) $key,
            $outcomeMessageId,
            $this->runtime->clock->now()->modify('+24 hours'),
        ));
    }

    private function cancelJob(SubmitJobContextSpec $spec): void
    {
        // §7.4: client cancellation terminates with job.error
        // (code CANCELLED, final_status "cancelled").
        $this->terminate(
            $spec,
            JobState::Cancelled,
            new JobError(JobError::CANCELLED, new ErrorPayload('CANCELLED', 'cooperative cancellation')),
        );
    }

    private function timeOutJob(SubmitJobContextSpec $spec): void
    {
        // §7.3: max_runtime_sec expiry terminates with job.error
        // (code TIMEOUT, final_status "timed_out").
        $this->terminate(
            $spec,
            JobState::TimedOut,
            new JobError(JobError::TIMED_OUT, new ErrorPayload('TIMEOUT', 'job exceeded max_runtime_sec')),
        );
    }

    private function terminate(
        SubmitJobContextSpec $spec,
        JobState $state,
        JobError $error,
    ): \Arcp\Ids\MessageId {
        $this->runtime->jobs->transition($spec->job, $state);
        $outcomeId = $this->runtime->emit($spec->session, $error, [
            'correlation_id' => $spec->env->id,
            'job_id' => $spec->job->id,
            'trace_id' => $spec->env->traceId,
        ]);
        $this->credentials->revoke($spec->job);
        return $outcomeId;
    }

    private function failJob(SubmitJobContextSpec $spec, ErrorPayload $payload): void
    {
        $outcomeId = $this->terminate($spec, JobState::Error, new JobError(JobError::ERROR, $payload));
        // Retryable failures intentionally do not consume the idempotency
        // key — the client is expected to retry. When `retryable` is unset
        // we fall back to the canonical default for the code (§12), so a
        // crash mapped to a retryable code (e.g. INTERNAL_ERROR) is not
        // recorded as the permanent idempotent outcome.
        if (!$payload->effectiveRetryable()) {
            $this->rememberIdempotent(
                $spec->session,
                $spec->env,
                $spec->msg,
                (string) $outcomeId,
            );
        }
    }
}
