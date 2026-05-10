<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use function Amp\async;

use Amp\Cancellation;
use Amp\Future;
use Arcp\Auth\AuthRouter;
use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Envelope\MessageType;
use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Envelope\Priority;
use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\InternalException;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\UnimplementedException;
use Arcp\Extensions\ExtensionRegistry;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Artifacts\ArtifactFetch;
use Arcp\Messages\Artifacts\ArtifactPut;
use Arcp\Messages\Artifacts\ArtifactRelease;
use Arcp\Messages\Control\Ack;
use Arcp\Messages\Control\Cancel;
use Arcp\Messages\Control\CancelAccepted;
use Arcp\Messages\Control\Interrupt;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Control\Ping;
use Arcp\Messages\Control\Pong;
use Arcp\Messages\Control\Resume;
use Arcp\Messages\Execution\AgentDelegate;
use Arcp\Messages\Execution\AgentHandoff;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobCancelled;
use Arcp\Messages\Execution\JobCompleted;
use Arcp\Messages\Execution\JobFailed;
use Arcp\Messages\Execution\JobSchedule;
use Arcp\Messages\Execution\JobStarted;
use Arcp\Messages\Execution\ToolError;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Messages\Execution\ToolResult;
use Arcp\Messages\Execution\WorkflowStart;
use Arcp\Messages\Permissions\LeaseRefresh;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Session\SessionAccepted;
use Arcp\Messages\Session\SessionClose;
use Arcp\Messages\Session\SessionOpen;
use Arcp\Messages\Session\SessionRejected;
use Arcp\Messages\Session\SessionUnauthenticated;
use Arcp\Messages\Subscriptions\Subscribe;
use Arcp\Messages\Subscriptions\SubscribeAccepted;
use Arcp\Messages\Subscriptions\SubscribeClosed;
use Arcp\Messages\Subscriptions\Unsubscribe;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Store\EventLog;
use Arcp\Transport\Transport;
use Arcp\Version;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Server-side runtime. Owns sessions, jobs, streams, subscriptions,
 * leases, artifacts, and the event log. The complete lifecycle from
 * `session.open` through `session.close` flows through {@see serve()}.
 *
 * The runtime is intentionally a single class: every manager is a
 * collaborator owned here, and the message-dispatch table sits in one
 * place so the protocol is auditable. RFC §6.3 (command/result/event
 * flow) is the structural backbone.
 */
final class ARCPRuntime
{
    public readonly MessageTypeRegistry $registry;
    public readonly EnvelopeSerializer $serializer;
    public readonly EventLog $eventLog;
    public readonly PendingRegistry $pending;
    public readonly LeaseManager $leases;
    public readonly ArtifactStore $artifacts;
    public readonly SubscriptionManager $subscriptions;
    public readonly JobManager $jobs;
    public readonly ClockInterface $clock;
    public readonly LoggerInterface $logger;
    public readonly Capabilities $advertisedCapabilities;

    /** @var array<string, ToolHandler> */
    private array $tools = [];

    public function __construct(
        ?MessageTypeRegistry $registry = null,
        ?EventLog $eventLog = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        ?Capabilities $capabilities = null,
        private readonly ?AuthRouter $authRouter = null,
        ?ExtensionRegistry $extensions = null,
        public readonly ?PeerInfo $runtimeIdentity = null,
    ) {
        $this->registry = $registry ?? MessageCatalog::create();
        $this->serializer = new EnvelopeSerializer($this->registry, $extensions);
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
        $this->eventLog = $eventLog ?? EventLog::inMemory($this->serializer, $this->clock);
        $this->pending = new PendingRegistry();
        $this->leases = new LeaseManager($this->clock);
        $this->artifacts = new ArtifactStore($this->clock);
        $this->subscriptions = new SubscriptionManager($this->serializer);
        $this->jobs = new JobManager();
        $this->advertisedCapabilities = $capabilities ?? Capabilities::defaultRuntime();
    }

    public function registerTool(string $name, ToolHandler $handler): void
    {
        $this->tools[$name] = $handler;
    }

    public function hasTool(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Drive a single peer connection. Performs the handshake, then the
     * read-loop until the transport closes or the session ends.
     */
    public function serve(Transport $transport, ?Cancellation $cancellation = null): void
    {
        $session = new Session($transport, isClient: false);
        try {
            $this->doHandshake($session, $cancellation);
            if ($session->state !== SessionState::Authenticated) {
                return;
            }
            $this->readLoop($session, $cancellation);
        } catch (\Throwable $e) {
            $this->logger->warning('serve() ended with error', ['error' => $e->getMessage()]);
        } finally {
            $this->pending->failAll(new \RuntimeException('session closed'));
            if (!$transport->isClosed()) {
                $transport->close();
            }
            $session->state = SessionState::Closed;
        }
    }

    /**
     * Background-serve a transport in its own fiber. Returns the future
     * for callers that want to await termination.
     *
     * @return Future<mixed>
     */
    public function serveAsync(Transport $transport, ?Cancellation $cancellation = null): Future
    {
        return async(fn () => $this->serve($transport, $cancellation));
    }

    private function doHandshake(Session $session, ?Cancellation $cancellation): void
    {
        $env = $session->transport->receive($cancellation);
        if ($env === null) {
            $session->state = SessionState::Closed;
            return;
        }
        if (!$env->payload instanceof SessionOpen) {
            $this->sendNoSession($session, new SessionRejected(new ErrorPayload(
                'INVALID_ARGUMENT',
                'expected session.open as first message',
            )), $env->id);
            $session->state = SessionState::Rejected;
            return;
        }

        $open = $env->payload;
        $auth = $open->auth;
        $capabilityCheck = $this->checkCapabilities($open->capabilities);
        if ($capabilityCheck !== null) {
            $this->sendNoSession(
                $session,
                new SessionRejected(new ErrorPayload('UNIMPLEMENTED', $capabilityCheck)),
                $env->id,
            );
            $session->state = SessionState::Rejected;
            return;
        }

        $router = $this->authRouter;
        if ($router === null) {
            // No auth router: allow `none` if anonymous capability is requested.
            if ($auth->scheme !== 'none' || !$open->capabilities->anonymous) {
                $this->sendNoSession(
                    $session,
                    new SessionUnauthenticated(new ErrorPayload('UNAUTHENTICATED', 'no auth router configured')),
                    $env->id,
                );
                $session->state = SessionState::Rejected;
                return;
            }
            $principal = $open->client->principal ?? 'anonymous';
        } else {
            try {
                $result = $router->verify($auth, $open->client);
            } catch (UnimplementedException $e) {
                $this->sendNoSession(
                    $session,
                    new SessionRejected(new ErrorPayload('UNIMPLEMENTED', $e->getMessage())),
                    $env->id,
                );
                $session->state = SessionState::Rejected;
                return;
            }
            if (!$result->accepted) {
                $this->sendNoSession(
                    $session,
                    new SessionUnauthenticated(new ErrorPayload(
                        'UNAUTHENTICATED',
                        $result->error ?? 'authentication failed',
                    )),
                    $env->id,
                );
                $session->state = SessionState::Rejected;
                return;
            }
            $principal = $result->principal ?? 'anonymous';
        }

        $session->sessionId = SessionId::random();
        $session->principal = $principal;
        $session->peerInfo = $open->client;
        $session->capabilities = $open->capabilities;
        $session->state = SessionState::Authenticated;

        $accepted = new SessionAccepted(
            sessionId: $session->sessionId,
            capabilities: $this->advertisedCapabilities,
            runtime: $this->runtimeIdentity ?? new PeerInfo(Version::IMPL_KIND, Version::IMPL_VERSION, trustLevel: 'trusted'),
        );
        $this->emit($session, $accepted, ['correlation_id' => $env->id]);
    }

    /**
     * Validate that the runtime supports every required capability the
     * client requested. Returns a human-readable mismatch message, or
     * `null` if everything is supported.
     */
    private function checkCapabilities(Capabilities $requested): ?string
    {
        if ($requested->scheduledJobs && !$this->advertisedCapabilities->scheduledJobs) {
            return 'scheduled_jobs unsupported (RFC §10.6 v0.2)';
        }
        if ($requested->agentHandoff && !$this->advertisedCapabilities->agentHandoff) {
            return 'agent_handoff unsupported (RFC §14 v0.2)';
        }
        if ($requested->checkpoints && !$this->advertisedCapabilities->checkpoints) {
            return 'checkpoints unsupported (RFC §19 v0.2)';
        }
        // Extension demands are checked once they are registered explicitly.
        return null;
    }

    private function readLoop(Session $session, ?Cancellation $cancellation): void
    {
        while (!$session->transport->isClosed()) {
            $env = $session->transport->receive($cancellation);
            if ($env === null) {
                return;
            }
            try {
                if (!$this->eventLog->append($env)) {
                    // Duplicate id — already processed (RFC §6.4 transport idempotency).
                    continue;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('event log append failed', ['error' => $e->getMessage()]);
            }

            $this->subscriptions->dispatch($env);

            try {
                $this->dispatch($session, $env);
            } catch (\Throwable $e) {
                $this->logger->warning('dispatch failed', ['type' => $env->type(), 'error' => $e->getMessage()]);
                if (!($e instanceof ARCPException)) {
                    $e = new InternalException($e->getMessage(), [], null, $e);
                }
                $this->emit($session, new Nack(ErrorPayload::fromException($e)), [
                    'correlation_id' => $env->id,
                ]);
            }
        }
    }

    private function dispatch(Session $session, Envelope $env): void
    {
        $msg = $env->payload;

        // Pending await routing: any envelope whose correlation id matches
        // an outstanding waiter is delivered there first (RFC §6.3).
        if ($env->correlationId !== null && $this->pending->resolve($env->correlationId, $msg)) {
            return;
        }

        match (true) {
            $msg instanceof Ping => $this->handlePing($session, $env, $msg),
            $msg instanceof Pong, $msg instanceof Ack => null,
            $msg instanceof SessionClose => $this->handleSessionClose($session, $env),
            $msg instanceof ToolInvoke => $this->handleToolInvoke($session, $env, $msg),
            $msg instanceof Cancel => $this->handleCancel($session, $env, $msg),
            $msg instanceof Interrupt => $this->handleInterrupt($session, $env, $msg),
            $msg instanceof Subscribe => $this->handleSubscribe($session, $env, $msg),
            $msg instanceof Unsubscribe => $this->handleUnsubscribe($session, $env),
            $msg instanceof ArtifactPut => $this->handleArtifactPut($session, $env, $msg),
            $msg instanceof ArtifactFetch => $this->handleArtifactFetch($session, $env, $msg),
            $msg instanceof ArtifactRelease => $this->handleArtifactRelease($session, $env, $msg),
            $msg instanceof Resume => $this->handleResume($session, $env, $msg),
            $msg instanceof LeaseRefresh => $this->handleLeaseRefresh($session, $env, $msg),
            // Unimplemented surfaces — nack with UNIMPLEMENTED.
            $msg instanceof JobSchedule, $msg instanceof WorkflowStart,
            $msg instanceof AgentDelegate, $msg instanceof AgentHandoff
                => $this->nack($session, $env, 'UNIMPLEMENTED', 'feature deferred to v0.2'),
            // Pong, ack, etc. with no waiter: silently drop.
            default => $this->logger->debug('unhandled message', ['type' => $msg::class]),
        };
    }

    private function handlePing(Session $session, Envelope $env, Ping $msg): void
    {
        $this->emit($session, new Pong($msg->nonce), ['correlation_id' => $env->id]);
    }

    private function handleSessionClose(Session $session, Envelope $env): void
    {
        $session->state = SessionState::Closing;
        // Cancel every still-running job so the close sequence terminates
        // promptly (RFC §9).
        foreach ($this->jobs->all() as $job) {
            if ($job->session === $session) {
                $this->jobs->cancel($job->id, 'session_closing');
            }
        }
        $session->transport->close();
    }

    private function handleToolInvoke(Session $session, Envelope $env, ToolInvoke $msg): void
    {
        if (!isset($this->tools[$msg->tool])) {
            $this->emit($session, new ToolError(new ErrorPayload(
                'NOT_FOUND',
                \sprintf('unknown tool: %s', $msg->tool),
            )), [
                'correlation_id' => $env->id,
                'trace_id' => $env->traceId,
            ]);
            return;
        }
        // Logical idempotency replay (RFC §6.4).
        if ($env->idempotencyKey !== null && $session->principal !== null) {
            $prior = $this->eventLog->lookupIdempotent($session->principal, (string) $env->idempotencyKey);
            if ($prior !== null) {
                $this->emit($session, new Ack('replay'), [
                    'correlation_id' => $env->id,
                    'trace_id' => $env->traceId,
                ]);
                return;
            }
        }

        $job = $this->jobs->start($session, $env->id, $env->traceId, $msg->tool);
        // job.accepted/started keyed on job_id; only the terminal
        // tool.result/tool.error envelopes carry correlation_id, so
        // synchronous invokeTool() callers see exactly one resolution.
        $this->emit($session, new JobAccepted(), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->emit($session, new JobStarted($this->clock->now()), [
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
        $this->jobs->transition($job, JobState::Running);

        $handler = $this->tools[$msg->tool];
        $job->future = async(function () use ($session, $env, $msg, $job, $handler): void {
            $sid = $session->sessionId ?? throw new InvalidArgumentException('session has no id');
            $ctx = new JobContext($this, $session, $job->id, $sid, $env->traceId);
            $ctx->cancellation = $job->cancellation;
            try {
                $value = $handler->invoke($msg->arguments, $ctx, $job->cancellation->getCancellation());
                $this->jobs->transition($job, JobState::Completed);
                $this->emit($session, new ToolResult($value), [
                    'correlation_id' => $env->id,
                    'job_id' => $job->id,
                    'trace_id' => $env->traceId,
                ]);
                $this->emit($session, new JobCompleted($value), [
                    'job_id' => $job->id,
                    'trace_id' => $env->traceId,
                ]);
                if ($env->idempotencyKey !== null && $session->principal !== null) {
                    $this->eventLog->rememberIdempotent(
                        $session->principal,
                        (string) $env->idempotencyKey,
                        (string) $env->id,
                        $this->clock->now()->modify('+24 hours'),
                    );
                }
            } catch (\Amp\CancelledException $e) {
                $this->jobs->transition($job, JobState::Cancelled);
                $payload = new ErrorPayload('CANCELLED', 'cooperative cancellation');
                $this->emit($session, new ToolError($payload), [
                    'correlation_id' => $env->id,
                    'job_id' => $job->id,
                    'trace_id' => $env->traceId,
                ]);
                $this->emit($session, new JobCancelled('cooperative', 'CANCELLED'), [
                    'job_id' => $job->id,
                    'trace_id' => $env->traceId,
                ]);
            } catch (ARCPException $e) {
                $this->jobs->transition($job, JobState::Failed);
                $payload = ErrorPayload::fromException($e);
                $this->emit($session, new ToolError($payload), [
                    'correlation_id' => $env->id,
                    'job_id' => $job->id,
                    'trace_id' => $env->traceId,
                ]);
                $this->emit($session, new JobFailed($payload), [
                    'job_id' => $job->id,
                    'trace_id' => $env->traceId,
                ]);
            } catch (\Throwable $e) {
                $this->jobs->transition($job, JobState::Failed);
                $payload = new ErrorPayload('INTERNAL', $e->getMessage());
                $this->emit($session, new ToolError($payload), [
                    'correlation_id' => $env->id,
                    'job_id' => $job->id,
                    'trace_id' => $env->traceId,
                ]);
                $this->emit($session, new JobFailed($payload), [
                    'job_id' => $job->id,
                    'trace_id' => $env->traceId,
                ]);
            }
        });
    }

    private function handleCancel(Session $session, Envelope $env, Cancel $msg): void
    {
        if ($msg->target === 'job') {
            $job = $this->jobs->tryGet(new JobId($msg->targetId));
            if ($job === null || $job->state->isTerminal()) {
                $this->nack($session, $env, 'FAILED_PRECONDITION', 'job already terminal');
                return;
            }
            $job->cancellation->cancel(new \Amp\CancelledException(new \RuntimeException($msg->reason)));
            $this->emit($session, new CancelAccepted($msg->deadlineMs), [
                'correlation_id' => $env->id,
                'job_id' => $job->id,
            ]);
            return;
        }
        $this->nack($session, $env, 'UNIMPLEMENTED', 'cancel target ' . $msg->target . ' deferred');
    }

    private function handleInterrupt(Session $session, Envelope $env, Interrupt $msg): void
    {
        $job = $this->jobs->tryGet(new JobId($msg->targetId));
        if ($job === null || $job->state->isTerminal()) {
            $this->nack($session, $env, 'FAILED_PRECONDITION', 'job not interruptible');
            return;
        }
        $job->state = JobState::Blocked;
        $this->emit($session, new \Arcp\Messages\Human\HumanInputRequest(
            prompt: $msg->prompt !== '' ? $msg->prompt : 'Job interrupted; provide guidance.',
            responseSchema: ['type' => 'object'],
            expiresAt: $this->clock->now()->modify('+5 minutes'),
        ), [
            'job_id' => $job->id,
            'trace_id' => $job->traceId,
            'priority' => Priority::High,
        ]);
        $this->emit($session, new Ack(), ['correlation_id' => $env->id]);
    }

    private function handleSubscribe(Session $session, Envelope $env, Subscribe $msg): void
    {
        // Authorization: a subscriber may only observe sessions they own
        // (their own session id, in the current single-tenant model).
        $sid = $session->sessionId !== null ? (string) $session->sessionId : null;
        $requested = $msg->filter['session_id'] ?? null;
        if (\is_array($requested)) {
            foreach ($requested as $r) {
                if ($sid !== null && \is_string($r) && $r !== $sid) {
                    $this->nack($session, $env, 'PERMISSION_DENIED', 'subscription session_id outside scope');
                    return;
                }
            }
        } elseif (\is_string($requested) && $sid !== null && $requested !== $sid) {
            $this->nack($session, $env, 'PERMISSION_DENIED', 'subscription session_id outside scope');
            return;
        }

        $sub = $this->subscriptions->compile($session, $msg);
        $this->emit($session, new SubscribeAccepted($sub->id), [
            'correlation_id' => $env->id,
            'subscription_id' => $sub->id,
        ]);

        // Backfill (RFC §13.3).
        $after = $msg->sinceMessageId ?? '';
        try {
            foreach ($this->eventLog->replayAfter($after) as $past) {
                if (!$sub->matches($past)) {
                    continue;
                }
                $session->transport->send(new Envelope(
                    id: MessageId::random(),
                    payload: new \Arcp\Messages\Subscriptions\SubscribeEvent(
                        $this->serializer->envelopeToArray($past),
                    ),
                    timestamp: $this->clock->now(),
                    sessionId: $session->sessionId,
                    subscriptionId: $sub->id,
                    priority: $past->priority,
                ));
            }
        } catch (\Throwable $e) {
            $this->emit($session, new SubscribeClosed('DATA_LOSS', $e->getMessage()), [
                'subscription_id' => $sub->id,
            ]);
            $this->subscriptions->close($sub->id);
            return;
        }
        $this->emit($session, new \Arcp\Messages\Subscriptions\SubscribeEvent([
            'arcp' => Version::PROTOCOL_VERSION,
            'id' => (string) MessageId::random(),
            'type' => 'event.emit',
            'timestamp' => $this->clock->now()->format(\DateTimeInterface::RFC3339_EXTENDED),
            'payload' => new EventEmit('subscription.backfill_complete')->toArray(),
        ]), ['subscription_id' => $sub->id]);
    }

    private function handleUnsubscribe(Session $session, Envelope $env): void
    {
        if ($env->subscriptionId === null) {
            $this->nack($session, $env, 'INVALID_ARGUMENT', 'unsubscribe missing subscription_id');
            return;
        }
        $closed = $this->subscriptions->close($env->subscriptionId);
        $this->emit($session, new Ack($closed ? 'closed' : 'unknown'), [
            'correlation_id' => $env->id,
        ]);
    }

    private function handleArtifactPut(Session $session, Envelope $env, ArtifactPut $msg): void
    {
        $bytes = base64_decode($msg->data, strict: true);
        if ($bytes === false) {
            $this->nack($session, $env, 'INVALID_ARGUMENT', 'artifact.put data must be base64');
            return;
        }
        $ref = $this->artifacts->put($session, $msg->mediaType, $bytes, $msg->retentionSeconds);
        $this->emit($session, $ref, ['correlation_id' => $env->id]);
    }

    private function handleArtifactFetch(Session $session, Envelope $env, ArtifactFetch $msg): void
    {
        try {
            $bytes = $this->artifacts->fetch($msg->artifactId);
        } catch (ARCPException $e) {
            $this->nack($session, $env, $e->code()->value, $e->getMessage());
            return;
        }
        $put = new ArtifactPut(
            mediaType: $this->artifacts->ref($msg->artifactId)->mediaType,
            data: base64_encode($bytes),
        );
        $this->emit($session, $put, ['correlation_id' => $env->id]);
    }

    private function handleArtifactRelease(Session $session, Envelope $env, ArtifactRelease $msg): void
    {
        $ok = $this->artifacts->release($msg->artifactId);
        $this->emit($session, new Ack($ok ? 'released' : 'unknown'), ['correlation_id' => $env->id]);
    }

    private function handleResume(Session $session, Envelope $env, Resume $msg): void
    {
        if ($msg->checkpointId !== null && $msg->afterMessageId === null) {
            $this->nack($session, $env, 'UNIMPLEMENTED', 'checkpoint resume deferred (RFC §19)');
            return;
        }
        $after = $msg->afterMessageId ?? '';
        try {
            foreach ($this->eventLog->replayAfter($after) as $past) {
                $session->transport->send($past);
            }
        } catch (InvalidArgumentException) {
            $this->emit($session, new ToolError(new ErrorPayload('DATA_LOSS', 'after_message_id retention expired')), [
                'correlation_id' => $env->id,
            ]);
            return;
        }
        $this->emit($session, new Ack('resumed'), ['correlation_id' => $env->id]);
    }

    private function handleLeaseRefresh(Session $session, Envelope $env, LeaseRefresh $msg): void
    {
        try {
            $extra = $msg->extendSeconds ?? 300;
            $current = $this->leases->get($msg->leaseId);
            $newExp = $current->expiresAt->modify('+' . $extra . ' seconds');
            $extended = $this->leases->extend($msg->leaseId, $newExp);
            $this->emit($session, new \Arcp\Messages\Permissions\LeaseExtended($extended->leaseId, $extended->expiresAt), [
                'correlation_id' => $env->id,
            ]);
        } catch (ARCPException $e) {
            $this->nack($session, $env, $e->code()->value, $e->getMessage());
        }
    }

    private function nack(Session $session, Envelope $cause, string $code, string $message): void
    {
        $this->emit($session, new Nack(new ErrorPayload($code, $message)), [
            'correlation_id' => $cause->id,
        ]);
    }

    /**
     * Build, log, send, and return the message id for an outbound envelope.
     *
     * @param array{
     *     correlation_id?: MessageId,
     *     job_id?: JobId|null,
     *     stream_id?: \Arcp\Ids\StreamId|null,
     *     subscription_id?: SubscriptionId|null,
     *     trace_id?: TraceId|null,
     *     priority?: Priority,
     * } $hints
     */
    public function emit(Session $session, MessageType $payload, array $hints = []): MessageId
    {
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: $payload,
            timestamp: $this->clock->now(),
            priority: $hints['priority'] ?? Priority::Normal,
            sessionId: $session->sessionId,
            jobId: $hints['job_id'] ?? null,
            streamId: $hints['stream_id'] ?? null,
            subscriptionId: $hints['subscription_id'] ?? null,
            traceId: $hints['trace_id'] ?? null,
            correlationId: $hints['correlation_id'] ?? null,
        );
        $this->eventLog->append($env);
        $this->subscriptions->dispatch($env);
        $session->transport->send($env);
        return $id;
    }

    /**
     * Send a no-session envelope (handshake responses before a session id
     * has been assigned).
     */
    private function sendNoSession(Session $session, MessageType $payload, MessageId $correlationId): void
    {
        $env = new Envelope(
            id: MessageId::random(),
            payload: $payload,
            timestamp: $this->clock->now(),
            correlationId: $correlationId,
        );
        $session->transport->send($env);
    }
}
