<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use function Amp\async;

use Amp\CancelledException;
use Arcp\Envelope\Envelope;
use Arcp\Errors\AgentVersionNotAvailableException;
use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\IdempotencyKey;
use Arcp\Messages\Control\Ack;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobCancelled;
use Arcp\Messages\Execution\JobCompleted;
use Arcp\Messages\Execution\JobFailed;
use Arcp\Messages\Execution\JobStarted;
use Arcp\Messages\Execution\ToolError;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Messages\Execution\ToolResult;
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

/**
 * Drives the `tool.invoke` lifecycle: not-found errors, idempotency
 * replays, job start, handler fiber, and the terminal result/error and
 * job.completed/failed/cancelled emissions.
 *
 * @internal
 */
final readonly class ToolInvocationHandler
{
    /** @param \Closure(AgentRef): ?ResolvedTool $resolveTool */
    public function __construct(
        private \Arcp\Runtime\ARCPRuntime $runtime,
        private \Closure $resolveTool,
        private CredentialLifecycle $credentials,
    ) {
    }

    public function handle(Session $session, Envelope $env, ToolInvoke $msg): void
    {
        try {
            $resolved = ($this->resolveTool)(AgentRef::parse($msg->tool));
        } catch (AgentVersionNotAvailableException $e) {
            $this->runtime->emit($session, new ToolError(ErrorPayload::fromException($e)), [
                'correlation_id' => $env->id,
                'trace_id' => $env->traceId,
            ]);
            return;
        }
        if (!$resolved instanceof ResolvedTool) {
            $this->emitNotFound($session, $env, $msg->tool);
            return;
        }
        if ($this->handledByIdempotencyReplay($session, $env)) {
            return;
        }
        try {
            $lease = $this->credentials->leaseFromArguments($msg->arguments, $resolved, $session);
        } catch (\Arcp\Errors\PermissionDeniedException $e) {
            $this->runtime->emit($session, new ToolError(ErrorPayload::fromException($e)), [
                'correlation_id' => $env->id,
                'trace_id' => $env->traceId,
            ]);
            return;
        }
        $job = $this->runtime->jobs->start(
            $session,
            $env,
            $resolved->name,
            $resolved->version,
            $lease instanceof LeaseGranted
                ? $lease->costBudget
                : CostBudget::fromInvocationArguments($msg->arguments),
            $lease,
        );
        $credentials = $this->credentials->issue($session, $env, $job, $lease);
        if ($credentials === null) {
            return;
        }
        $this->emitJobAcceptedAndStarted($session, $env, $job, $credentials);
        $this->runtime->jobs->transition($job, JobState::Running);
        $job->future = async(function () use ($session, $env, $msg, $job, $resolved): void {
            $this->runHandler(new ToolJobContextSpec($session, $env, $msg, $job), $resolved->handler);
        });
    }

    private function emitNotFound(Session $session, Envelope $env, string $tool): void
    {
        $this->runtime->emit($session, new ToolError(new ErrorPayload(
            'NOT_FOUND',
            \sprintf('unknown tool: %s', $tool),
        )), [
            'correlation_id' => $env->id,
            'trace_id' => $env->traceId,
        ]);
    }

    /**
     * Logical idempotency replay (RFC §6.4). Returns true when the request
     * has already been processed and the runtime has re-emitted the
     * original terminal correlated response for the duplicate.
     */
    private function handledByIdempotencyReplay(Session $session, Envelope $env): bool
    {
        $key = $env->idempotencyKey;
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
            // Fallback: if the original outcome envelope is no longer in the
            // log (e.g. retention purged it), still ack the duplicate so
            // synchronous callers stop waiting.
            $this->runtime->emit($session, new Ack('replay'), $hints);
        }
        return true;
    }

    /**
     * @param list<Credential> $credentials
     */
    private function emitJobAcceptedAndStarted(
        Session $session,
        Envelope $env,
        Job $job,
        array $credentials,
    ): void {
        // job.accepted/started keyed on job_id; only the terminal
        // tool.result/tool.error envelopes carry correlation_id, so
        // synchronous invokeTool() callers see exactly one resolution.
        $payloadCredentials = $credentials === []
            ? null
            : array_map(fn (Credential $cred): array => $cred->toArray(), $credentials);
        $this->runtime->emit($session, new JobAccepted(credentials: $payloadCredentials), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->runtime->emit($session, new JobStarted($this->runtime->clock->now()), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
    }

    private function runHandler(ToolJobContextSpec $spec, ToolHandler $handler): void
    {
        $session = $spec->session;
        $env = $spec->env;
        $job = $spec->job;
        $sid = $session->sessionId ?? throw new InvalidArgumentException('session has no id');
        $ctx = new JobContext($this->runtime, $session, $job->id, $sid, $env->traceId);
        $ctx->cancellation = $job->cancellation;
        try {
            $value = $handler->invoke(
                $spec->msg->arguments,
                $ctx,
                $job->cancellation->getCancellation(),
            );
            $this->completeJob($session, $env, $job, $value);
        } catch (CancelledException) {
            $this->cancelJob($session, $env, $job);
        } catch (ARCPException $e) {
            $this->failJob($spec, ErrorPayload::fromException($e));
        } catch (\Throwable $e) {
            $this->failJob($spec, new ErrorPayload('INTERNAL', $e->getMessage()));
        }
    }

    private function completeJob(Session $session, Envelope $env, Job $job, mixed $value): void
    {
        $this->runtime->jobs->transition($job, JobState::Completed);
        $outcomeId = $this->runtime->emit($session, new ToolResult($value), [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->runtime->emit($session, new JobCompleted($value), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->credentials->revoke($job);
        $this->rememberIdempotent($session, $env, (string) $outcomeId);
    }

    private function rememberIdempotent(Session $session, Envelope $env, string $outcomeMessageId): void
    {
        $key = $env->idempotencyKey;
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

    private function cancelJob(Session $session, Envelope $env, Job $job): void
    {
        $this->runtime->jobs->transition($job, JobState::Cancelled);
        $payload = new ErrorPayload('CANCELLED', 'cooperative cancellation');
        $this->runtime->emit($session, new ToolError($payload), [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->runtime->emit($session, new JobCancelled('cooperative', 'CANCELLED'), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->credentials->revoke($job);
    }

    private function failJob(ToolJobContextSpec $spec, ErrorPayload $payload): void
    {
        $session = $spec->session;
        $env = $spec->env;
        $job = $spec->job;
        $this->runtime->jobs->transition($job, JobState::Failed);
        $outcomeId = $this->runtime->emit($session, new ToolError($payload), [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->runtime->emit($session, new JobFailed($payload), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->credentials->revoke($job);
        // Retryable failures intentionally do not consume the idempotency
        // key — the client is expected to retry. Non-retryable failures
        // (including the default `null` case) are part of the recorded
        // outcome and must replay identically on duplicate submission.
        if ($payload->retryable !== true) {
            $this->rememberIdempotent($session, $env, (string) $outcomeId);
        }
    }
}
