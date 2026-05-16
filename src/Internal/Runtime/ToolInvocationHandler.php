<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use function Amp\async;

use Amp\CancelledException;
use Arcp\Envelope\Envelope;
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
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Job;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\JobState;
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
final class ToolInvocationHandler
{
    /** @var \Closure(string): ?ToolHandler */
    private readonly \Closure $resolveTool;

    /** @param \Closure(string): ?ToolHandler $resolveTool */
    public function __construct(
        private readonly ARCPRuntime $runtime,
        \Closure $resolveTool,
    ) {
        $this->resolveTool = $resolveTool;
    }

    public function handle(Session $session, Envelope $env, ToolInvoke $msg): void
    {
        $handler = ($this->resolveTool)($msg->tool);
        if (!$handler instanceof ToolHandler) {
            $this->emitNotFound($session, $env, $msg->tool);
            return;
        }
        if ($this->handledByIdempotencyReplay($session, $env)) {
            return;
        }
        $job = $this->runtime->jobs->start($session, $env, $msg->tool);
        $this->emitJobAcceptedAndStarted($session, $env, $job);
        $this->runtime->jobs->transition($job, JobState::Running);
        $job->future = async(function () use ($session, $env, $msg, $job, $handler): void {
            $this->runHandler(new ToolJobContextSpec($session, $env, $msg, $job), $handler);
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
     * has already been processed and the runtime has emitted a replay Ack.
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
        $this->runtime->emit($session, new Ack('replay'), [
            'correlation_id' => $env->id,
            'trace_id' => $env->traceId,
        ]);
        return true;
    }

    private function emitJobAcceptedAndStarted(Session $session, Envelope $env, Job $job): void
    {
        // job.accepted/started keyed on job_id; only the terminal
        // tool.result/tool.error envelopes carry correlation_id, so
        // synchronous invokeTool() callers see exactly one resolution.
        $this->runtime->emit($session, new JobAccepted(), [
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
        $this->runtime->emit($session, new ToolResult($value), [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->runtime->emit($session, new JobCompleted($value), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->rememberIdempotent($session, $env);
    }

    private function rememberIdempotent(Session $session, Envelope $env): void
    {
        $key = $env->idempotencyKey;
        $principal = $session->principal;
        if (!$key instanceof IdempotencyKey || $principal === null) {
            return;
        }
        $this->runtime->eventLog->rememberIdempotent(new IdempotencyRecord(
            $principal,
            (string) $key,
            (string) $env->id,
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
    }

    private function failJob(ToolJobContextSpec $spec, ErrorPayload $payload): void
    {
        $session = $spec->session;
        $env = $spec->env;
        $job = $spec->job;
        $this->runtime->jobs->transition($job, JobState::Failed);
        $this->runtime->emit($session, new ToolError($payload), [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->runtime->emit($session, new JobFailed($payload), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
    }
}
