<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use function Amp\async;

use Amp\CancelledException;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageType;
use Arcp\Envelope\Priority;
use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Messages\Control\Ack;
use Arcp\Messages\Control\Cancel;
use Arcp\Messages\Control\CancelAccepted;
use Arcp\Messages\Control\Interrupt;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Control\Ping;
use Arcp\Messages\Control\Pong;
use Arcp\Messages\Control\Resume;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;
use Arcp\Messages\Permissions\LeaseExtended;
use Arcp\Messages\Permissions\LeaseRefresh;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Job;
use Arcp\Runtime\JobState;
use Arcp\Runtime\Session;
use Arcp\Runtime\SessionState;

/**
 * Owns the small ARCP message handlers: ping/pong, session.close,
 * cancel, interrupt, resume, lease.refresh, plus shared nack/no-session
 * helpers used by sibling collaborators.
 *
 * @internal
 */
final readonly class LifecycleHandler
{
    public function __construct(private ARCPRuntime $runtime)
    {
    }

    public function handlePing(Session $session, Envelope $env, Ping $msg): void
    {
        $this->runtime->emit($session, new Pong($msg->nonce), [
            'correlation_id' => $env->id,
        ]);
    }

    public function handleSessionClose(Session $session): void
    {
        $session->state = SessionState::Closing;
        // Cancel every still-running job so the close sequence terminates
        // promptly (RFC §9).
        foreach ($this->runtime->jobs->all() as $job) {
            if ($job->session === $session) {
                $this->runtime->jobs->cancel($job->id, 'session_closing');
            }
        }
        $session->transport->close();
    }

    public function handleCancel(Session $session, Envelope $env, Cancel $msg): void
    {
        if ($msg->target !== 'job') {
            $this->nack(
                $session,
                $env,
                'UNIMPLEMENTED',
                'cancel target ' . $msg->target . ' deferred',
            );
            return;
        }
        $job = $this->runtime->jobs->tryGet(new JobId($msg->targetId));
        if (!$job instanceof Job) {
            $this->nack($session, $env, 'NOT_FOUND', 'job not found');
            return;
        }
        if ($job->state->isTerminal()) {
            $this->nack($session, $env, 'FAILED_PRECONDITION', 'job already terminal');
            return;
        }
        $job->cancellation->cancel(
            new CancelledException(new \RuntimeException($msg->reason)),
        );
        $this->runtime->emit($session, new CancelAccepted($msg->deadlineMs), [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
        ]);
    }

    public function handleInterrupt(Session $session, Envelope $env, Interrupt $msg): void
    {
        $job = $this->runtime->jobs->tryGet(new JobId($msg->targetId));
        if (!$job instanceof Job) {
            $this->nack($session, $env, 'NOT_FOUND', 'job not found');
            return;
        }
        if ($job->state->isTerminal()) {
            $this->nack($session, $env, 'FAILED_PRECONDITION', 'job already terminal');
            return;
        }
        // Only the owning session may interrupt a job (cf. handleCancel).
        if ($job->session !== $session) {
            $this->nack($session, $env, 'PERMISSION_DENIED', 'job not owned by this session');
            return;
        }
        $job->state = JobState::Blocked;
        $requestId = $this->runtime->emit($session, new HumanInputRequest(
            prompt: $msg->prompt !== '' ? $msg->prompt : 'Job interrupted; provide guidance.',
            responseSchema: ['type' => 'object'],
            expiresAt: $this->runtime->clock->now()->modify('+5 minutes'),
        ), [
            'job_id' => $job->id,
            'trace_id' => $job->invocation->traceId,
            'priority' => Priority::High,
        ]);
        $this->awaitInterruptResponse($job, $requestId);
        $this->runtime->emit($session, new Ack(), ['correlation_id' => $env->id]);
    }

    /**
     * Register a waiter for the interrupt's human-input request so the
     * correlated response is routed back here, delivered to the job's
     * mailbox, and the job restored to `running`. The state is also
     * restored on deadline expiry or cancellation.
     */
    private function awaitInterruptResponse(Job $job, MessageId $requestId): void
    {
        async(function () use ($job, $requestId): void {
            try {
                $response = $this->runtime->pending->awaitResponse($requestId, 300.0);
                if ($response instanceof HumanInputResponse) {
                    $job->deliverInterruptResponse($response);
                }
            } catch (\Throwable) {
                // Deadline / cancellation: fall through to restore state.
            } finally {
                if ($job->state === JobState::Blocked) {
                    $job->state = JobState::Running;
                }
            }
        });
    }

    public function handleResume(Session $session, Envelope $env, Resume $msg): void
    {
        if ($msg->checkpointId !== null && $msg->afterMessageId === null) {
            $this->nack($session, $env, 'UNIMPLEMENTED', 'checkpoint resume deferred (RFC §19)');
            return;
        }
        $sessionId = $session->sessionId;
        if ($sessionId === null) {
            $this->nack($session, $env, 'FAILED_PRECONDITION', 'resume requires an authenticated session');
            return;
        }
        $after = $msg->afterMessageId ?? '';
        try {
            foreach ($this->runtime->eventLog->replayAfterForSession($after, $sessionId) as $past) {
                $session->transport->send($past);
            }
        } catch (InvalidArgumentException) {
            // Resume is a lifecycle operation, not a tool invocation; surface
            // the failure as a correlated nack like every other lifecycle path.
            $this->nack($session, $env, 'DATA_LOSS', 'after_message_id retention expired');
            return;
        }
        $this->runtime->emit($session, new Ack('resumed'), ['correlation_id' => $env->id]);
    }

    public function handleLeaseRefresh(Session $session, Envelope $env, LeaseRefresh $msg): void
    {
        $sessionId = $session->sessionId;
        if (!$sessionId instanceof SessionId) {
            $this->nack($session, $env, 'PERMISSION_DENIED', 'lease refresh requires an authenticated session');
            return;
        }
        try {
            $extra = $msg->extendSeconds ?? 300;
            // Resolve through the session-scoped accessor so a session cannot
            // refresh a lease that belongs to another session (auth bypass).
            $current = $this->runtime->leases->getForSession($msg->leaseId, $sessionId);
            $newExp = $current->expiresAt->modify('+' . $extra . ' seconds');
            $extended = $this->runtime->leases->extend($msg->leaseId, $newExp);
            $this->runtime->emit(
                $session,
                new LeaseExtended($extended->leaseId, $extended->expiresAt),
                ['correlation_id' => $env->id],
            );
        } catch (ARCPException $e) {
            $this->nack($session, $env, $e->code()->value, $e->getMessage());
        }
    }

    public function nack(Session $session, Envelope $cause, string $code, string $message): void
    {
        $this->runtime->emit($session, new Nack(new ErrorPayload($code, $message)), [
            'correlation_id' => $cause->id,
        ]);
    }

    /**
     * Send a no-session envelope (handshake responses before a session id
     * has been assigned).
     */
    public function sendNoSession(
        Session $session,
        MessageType $payload,
        MessageId $correlationId,
    ): void {
        $env = new Envelope(
            id: MessageId::random(),
            payload: $payload,
            timestamp: $this->runtime->clock->now(),
            correlationId: $correlationId,
        );
        $session->transport->send($env);
    }
}
