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
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Messages\Control\Interrupt;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Execution\JobCancel;
use Arcp\Messages\Execution\JobCancelled;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;
use Arcp\Messages\Permissions\LeaseExtended;
use Arcp\Messages\Permissions\LeaseRefresh;
use Arcp\Messages\Session\SessionAck;
use Arcp\Messages\Session\SessionClose;
use Arcp\Messages\Session\SessionClosed;
use Arcp\Messages\Session\SessionPing;
use Arcp\Messages\Session\SessionPong;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Job;
use Arcp\Runtime\Session;
use Arcp\Runtime\SessionState;

/**
 * Owns the small ARCP message handlers: session.ping/pong, session.ack,
 * session.close, job.cancel, interrupt, lease.refresh, plus shared
 * nack/no-session helpers used by sibling collaborators.
 *
 * @internal
 */
final readonly class LifecycleHandler
{
    public function __construct(private ARCPRuntime $runtime)
    {
    }

    public function handlePing(Session $session, Envelope $env, SessionPing $msg): void
    {
        // §6.4: respond promptly with session.pong carrying ping_nonce and
        // the receiver-side received_at timestamp.
        $this->runtime->emit(
            $session,
            new SessionPong($msg->nonce, $this->runtime->clock->now()),
            ['correlation_id' => $env->id],
        );
    }

    /**
     * §6.5: advance the client's processing watermark and free buffered
     * events with `seq <= last_processed_seq` ahead of the time-based
     * resume window. Stale (lower) acks are ignored.
     */
    public function handleSessionAck(Session $session, SessionAck $msg): void
    {
        $current = $session->lastAckedEventSeq;
        if ($current !== null && $msg->lastProcessedSeq <= $current) {
            return;
        }
        $session->lastAckedEventSeq = $msg->lastProcessedSeq;
        $sessionId = $session->sessionId;
        if ($sessionId instanceof SessionId) {
            $this->runtime->eventLog->releaseAcked($sessionId, $msg->lastProcessedSeq);
        }
    }

    /**
     * §6.7: acknowledge with `session.closed` (echoing the reason), then
     * release the transport. In-flight jobs MUST NOT be cancelled — they
     * continue running and remain resumable within the resume window.
     */
    public function handleSessionClose(Session $session, Envelope $env, SessionClose $msg): void
    {
        $session->state = SessionState::Closing;
        $this->runtime->emit($session, new SessionClosed($msg->reason), [
            'correlation_id' => $env->id,
        ]);
        $session->transport->close();
    }

    public function handleCancel(Session $session, Envelope $env, JobCancel $msg): void
    {
        $job = $this->runtime->jobs->tryGet($msg->jobId);
        if (!$job instanceof Job) {
            $this->nack($session, $env, 'JOB_NOT_FOUND', 'job not found');
            return;
        }
        if ($job->state->isTerminal()) {
            $this->nack($session, $env, 'INVALID_REQUEST', 'job already terminal');
            return;
        }
        $job->cancellation->cancel(
            new CancelledException(new \RuntimeException($msg->reason)),
        );
        // §7.4: acknowledge with job.cancelled; the cooperative handler
        // fiber then terminates the job with job.error (code CANCELLED,
        // final_status "cancelled").
        $this->runtime->emit($session, new JobCancelled($msg->reason), [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
        ]);
    }

    public function handleInterrupt(Session $session, Envelope $env, Interrupt $msg): void
    {
        $job = $this->runtime->jobs->tryGet(new JobId($msg->targetId));
        if (!$job instanceof Job) {
            $this->nack($session, $env, 'JOB_NOT_FOUND', 'job not found');
            return;
        }
        if ($job->state->isTerminal()) {
            $this->nack($session, $env, 'INVALID_REQUEST', 'job already terminal');
            return;
        }
        // Only the owning session may interrupt a job (cf. handleCancel).
        if ($job->session !== $session) {
            $this->nack($session, $env, 'PERMISSION_DENIED', 'job not owned by this session');
            return;
        }
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
        $this->runtime->emit($session, new EventEmit('interrupt.accepted'), [
            'correlation_id' => $env->id,
        ]);
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
                // Deadline / cancellation: nothing further to deliver. The
                // job keeps running throughout (§7.3 has no blocked state).
            }
        });
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
