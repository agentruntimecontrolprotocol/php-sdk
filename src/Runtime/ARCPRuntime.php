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
use Arcp\Errors\AgentVersionNotAvailableException;
use Arcp\Extensions\ExtensionRegistry;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\StreamId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;
use Arcp\Internal\Runtime\ArtifactDispatcher;
use Arcp\Internal\Runtime\CredentialLifecycle;
use Arcp\Internal\Runtime\Dispatcher;
use Arcp\Internal\Runtime\HandshakeNegotiator;
use Arcp\Internal\Runtime\JobListHandler;
use Arcp\Internal\Runtime\JobSubmitHandler;
use Arcp\Internal\Runtime\LifecycleHandler;
use Arcp\Internal\Runtime\SubscriptionRouter;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobError;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Execution\JobResult;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Session\SessionAck;
use Arcp\Messages\Session\SessionPing;
use Arcp\Messages\Session\SessionPong;
use Arcp\Runtime\Credentials\CredentialProvisioner;
use Arcp\Runtime\Credentials\CredentialStore;
use Arcp\Runtime\Credentials\InMemoryCredentialStore;
use Arcp\Store\EventLog;
use Arcp\Transport\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

/**
 * Server-side runtime. Owns sessions, jobs, streams, subscriptions,
 * leases, artifacts, and the event log. The complete lifecycle from
 * `session.hello` through `session.close` flows through {@see serve()}.
 *
 * The protocol surface is intentionally a single public entry point; the
 * per-message handlers live in {@see \Arcp\Internal\Runtime} and are not
 * part of the BC promise. RFC §6.3 (command/result/event flow) is the
 * structural backbone.
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
    public readonly CredentialStore $credentials;
    public readonly ClockInterface $clock;
    public readonly LoggerInterface $logger;
    public readonly Capabilities $advertisedCapabilities;

    /** @var array<string, array<string, ToolHandler>> Empty version key means unversioned. */
    private array $tools = [];

    /** @var array<string, string> */
    private array $defaultToolVersions = [];

    /**
     * §6.3 resume registry: parked sessions keyed by their current
     * resume token, plus the event-loop timer that expires each park
     * when the resume window elapses.
     *
     * @var array<string, Session>
     */
    private array $resumable = [];

    /** @var array<string, string> token => EventLoop timer id */
    private array $resumeExpiry = [];

    private readonly HandshakeNegotiator $handshake;
    private readonly Dispatcher $dispatcher;

    /**
     * @size-check-suppress public BC; superseded by RuntimeConfig (use ::withConfig).
     */
    public function __construct(
        ?MessageTypeRegistry $registry = null,
        ?EventLog $eventLog = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        ?Capabilities $capabilities = null,
        ?AuthRouter $authRouter = null,
        ?ExtensionRegistry $extensions = null,
        public readonly ?PeerInfo $runtimeIdentity = null,
        public readonly ?CredentialProvisioner $credentialProvisioner = null,
        ?CredentialStore $credentialStore = null,
        public readonly int $resumeWindowSec = 600,
        public readonly int $heartbeatIntervalSec = 30,
    ) {
        $this->registry = $registry ?? MessageCatalog::create();
        $this->serializer = new EnvelopeSerializer($this->registry, $extensions);
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
        $this->eventLog = $eventLog ?? EventLog::inMemory($this->serializer, $this->clock);
        $this->pending = new PendingRegistry();
        $this->leases = new LeaseManager($this->clock);
        $this->artifacts = new ArtifactStore($this->clock);
        $this->subscriptions = new SubscriptionManager($this->serializer, $this->clock, $this->logger);
        $this->jobs = new JobManager($this->clock);
        $this->credentials = $credentialStore ?? new InMemoryCredentialStore();
        if (
            $this->credentialProvisioner instanceof CredentialProvisioner
            && !$this->credentials->supportsDurableRevocation()
        ) {
            throw new \InvalidArgumentException('provisioned credentials require a durable revocation store');
        }
        // §14: revocation is a durability concern. On startup, replay any
        // credentials the durable store still holds outstanding — these were
        // issued by a prior runtime instance that terminated before revoking
        // them — so the provisioner clears dangling upstream spend authority
        // before serve() accepts traffic.
        if ($this->credentialProvisioner instanceof CredentialProvisioner) {
            $this->replayOutstandingRevocations($this->credentialProvisioner);
        }
        $this->advertisedCapabilities = $capabilities ?? Capabilities::defaultRuntime();

        $lifecycle = new LifecycleHandler($this);
        $this->handshake = new HandshakeNegotiator(
            $this,
            $lifecycle,
            $authRouter,
            $this->runtimeIdentity,
        );
        $jobSubmit = new JobSubmitHandler(
            $this,
            fn (AgentRef $ref): ?ResolvedTool => $this->resolveTool($ref),
            new CredentialLifecycle($this),
        );
        $this->dispatcher = new Dispatcher(
            $this,
            $lifecycle,
            $jobSubmit,
            new SubscriptionRouter($this, $lifecycle),
            new ArtifactDispatcher($this, $lifecycle),
            new JobListHandler($this),
        );
    }

    /**
     * Bundle the optional dependencies into a {@see RuntimeConfig} and
     * construct the runtime from it. Equivalent to calling the constructor
     * with named arguments; kept additively to make future BC easier.
     */
    public static function withConfig(RuntimeConfig $config): self
    {
        return new self(
            $config->registry,
            $config->eventLog,
            $config->clock,
            $config->logger,
            $config->capabilities,
            $config->authRouter,
            $config->extensions,
            $config->runtimeIdentity,
            $config->credentialProvisioner,
            $config->credentialStore,
            $config->resumeWindowSec ?? 600,
            $config->heartbeatIntervalSec ?? 30,
        );
    }

    /**
     * §14: drain credentials the durable store still reports outstanding,
     * revoking each at the upstream and dropping it from the store on
     * success. Permanent failures are retained for a later retry and
     * surfaced to operators via the logger.
     */
    private function replayOutstandingRevocations(CredentialProvisioner $provisioner): void
    {
        foreach ($this->credentials->outstanding() as $entry) {
            $jobId = new JobId($entry['job_id']);
            $credentialId = $entry['credential_id'];
            $revoked = false;
            for ($attempt = 1; $attempt <= 2 && !$revoked; $attempt++) {
                try {
                    $provisioner->revoke($credentialId);
                    $revoked = true;
                } catch (\Throwable $e) {
                    if ($attempt >= 2) {
                        $this->logger->error(
                            'startup credential revocation failed; record retained for retry',
                            [
                                'credential_id' => $credentialId,
                                'job_id' => $entry['job_id'],
                                'error' => $e->getMessage(),
                            ],
                        );
                    }
                }
            }
            if ($revoked) {
                $this->credentials->remove($jobId, $credentialId);
            }
        }
    }

    public function registerTool(string $name, ToolHandler $handler): void
    {
        $ref = AgentRef::parse($name);
        $this->tools[$ref->name][$ref->version ?? ''] = $handler;
    }

    public function registerToolVersion(string $name, string $version, ToolHandler $handler): void
    {
        $ref = new AgentRef($name, $version);
        $this->tools[$ref->name][$ref->version ?? ''] = $handler;
    }

    /**
     * @throws \Arcp\Errors\AgentVersionNotAvailableException if the
     *                                                        requested `(name, version)` pair has not been registered.
     */
    public function setDefaultToolVersion(string $name, string $version): void
    {
        $ref = new AgentRef($name, $version);
        if (!isset($this->tools[$ref->name][$version])) {
            throw new AgentVersionNotAvailableException($ref->name, $version);
        }
        $this->defaultToolVersions[$ref->name] = $version;
    }

    public function hasTool(string $name): bool
    {
        $ref = AgentRef::parse($name);
        try {
            return $this->resolveTool($ref) instanceof ResolvedTool;
        } catch (AgentVersionNotAvailableException) {
            return false;
        }
    }

    public function resolveTool(AgentRef $ref): ?ResolvedTool
    {
        $bucket = $this->tools[$ref->name] ?? null;
        if ($bucket === null || $bucket === []) {
            return null;
        }
        if ($ref->version !== null) {
            $handler = $bucket[$ref->version] ?? null;
            if (!$handler instanceof ToolHandler) {
                throw new AgentVersionNotAvailableException($ref->name, $ref->version);
            }
            return new ResolvedTool($ref->name, $ref->version, $handler);
        }
        $default = $this->defaultToolVersions[$ref->name] ?? null;
        if ($default !== null && isset($bucket[$default])) {
            return new ResolvedTool($ref->name, $default, $bucket[$default]);
        }
        if (isset($bucket[''])) {
            return new ResolvedTool($ref->name, null, $bucket['']);
        }
        // No default and no unversioned handler: only resolve when exactly
        // one version is registered. Picking an arbitrary version from a
        // multi-version bucket would be non-deterministic across deployments.
        if (\count($bucket) === 1) {
            $only = array_key_first($bucket);
            return new ResolvedTool($ref->name, $only, $bucket[$only]);
        }
        throw new AgentVersionNotAvailableException($ref->name, '(default)');
    }

    public function advertisedCapabilitiesForSession(): Capabilities
    {
        $capabilities = $this->advertisedCapabilities->withAgents($this->agentInventory());
        if ($this->credentialProvisioner instanceof CredentialProvisioner) {
            $capabilities = $capabilities->withFeatures([
                ...$capabilities->features,
                'provisioned_credentials',
                'model.use',
            ]);
        }
        return $capabilities;
    }

    /**
     * @return list<array{name: string, versions: list<string>, default?: string}>
     */
    private function agentInventory(): array
    {
        $out = [];
        foreach ($this->tools as $name => $bucket) {
            $versions = [];
            foreach (array_keys($bucket) as $version) {
                if ($version !== '') {
                    $versions[] = $version;
                }
            }
            $entry = ['name' => $name, 'versions' => $versions];
            $default = $this->defaultToolVersions[$name] ?? null;
            if ($default !== null && \in_array($default, $versions, true)) {
                $entry['default'] = $default;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Drive a single peer connection. Performs the handshake, then the
     * read-loop until the transport closes or the session ends.
     *
     * A `session.hello` carrying a valid resume token reattaches the
     * parked session (§6.3): the loop continues serving that session's
     * identity — and its in-flight jobs — over the new transport.
     */
    public function serve(Transport $transport, ?Cancellation $cancellation = null): void
    {
        $session = new Session($transport, isClient: false);
        try {
            $session = $this->handshake->negotiate($session, $cancellation);
            if ($session->state !== SessionState::Authenticated) {
                return;
            }
            $this->dispatcher->readLoop($session, $cancellation);
        } catch (\Throwable $e) {
            $this->logger->warning('serve() ended with error', ['error' => $e->getMessage()]);
        } finally {
            $this->pending->failAll(new \RuntimeException('session closed'));
            if (!$transport->isClosed()) {
                $transport->close();
            }
            $this->finishServe($session);
        }
    }

    /**
     * End-of-connection disposition: park resumable sessions for the
     * §6.3 resume window (jobs keep running, sequenced events buffer);
     * everything else closes. Terminal labels set during dispatch
     * (Rejected/Evicted) are preserved.
     */
    private function finishServe(Session $session): void
    {
        if ($session->state->isTerminal()) {
            return;
        }
        if ($session->resumeToken !== null && $session->sessionId !== null) {
            $this->parkResumable($session);
            return;
        }
        $session->state = SessionState::Closed;
    }

    /**
     * §6.3: hold a session for the resume window. In-flight jobs keep
     * running; sequenced outbound messages are buffered for replay. When
     * the window elapses without a resume, the session is torn down and
     * its jobs cancelled.
     */
    public function parkResumable(Session $session): void
    {
        $token = $session->resumeToken;
        if ($token === null) {
            $session->state = SessionState::Closed;
            return;
        }
        $session->state = SessionState::Parked;
        $this->resumable[$token] = $session;
        $this->resumeExpiry[$token] = EventLoop::delay(
            (float) $this->resumeWindowSec,
            fn () => $this->expireResumable($token),
        );
    }

    /**
     * Claim a parked session by resume token, cancelling its expiry
     * timer. Returns null when the token is unknown or already expired.
     */
    public function takeResumable(string $token): ?Session
    {
        $session = $this->resumable[$token] ?? null;
        if ($session === null) {
            return null;
        }
        unset($this->resumable[$token]);
        $timer = $this->resumeExpiry[$token] ?? null;
        if ($timer !== null) {
            EventLoop::cancel($timer);
            unset($this->resumeExpiry[$token]);
        }
        return $session;
    }

    private function expireResumable(string $token): void
    {
        $session = $this->resumable[$token] ?? null;
        unset($this->resumable[$token], $this->resumeExpiry[$token]);
        if ($session === null || $session->state !== SessionState::Parked) {
            return;
        }
        // The resume window elapsed: the session is gone for good, so its
        // in-flight jobs no longer have an owner to report to.
        foreach ($this->jobs->all() as $job) {
            if ($job->session === $session && !$job->state->isTerminal()) {
                $this->jobs->cancel($job->id, 'resume_window_expired');
            }
        }
        $session->state = SessionState::Closed;
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

    /**
     * Build, log, send, and return the message id for an outbound envelope.
     *
     * @param array{
     *     correlation_id?: MessageId,
     *     message_id?: MessageId,
     *     job_id?: JobId|null,
     *     stream_id?: StreamId|null,
     *     subscription_id?: SubscriptionId|null,
     *     trace_id?: TraceId|null,
     *     priority?: Priority,
     *     sequenced?: bool
     * } $hints
     */
    public function emit(Session $session, MessageType $payload, array $hints = []): MessageId
    {
        $id = $hints['message_id'] ?? MessageId::random();
        $redactedPayload = $this->redactedPayload($payload);
        $jobId = $hints['job_id'] ?? null;
        // Top-level command rejections reuse the job.error type but are
        // NOT job events: callers opt out of event_seq via the hint.
        $sequenced = $hints['sequenced'] ?? $this->isSequenced($payload);
        $eventSeq = $sequenced ? $session->nextEventSeq() : null;
        $env = new Envelope(
            id: $id,
            payload: $payload,
            timestamp: $this->clock->now(),
            priority: $hints['priority'] ?? Priority::Normal,
            sessionId: $session->sessionId,
            jobId: $jobId,
            eventSeq: $eventSeq,
            streamId: $hints['stream_id'] ?? null,
            subscriptionId: $hints['subscription_id'] ?? null,
            traceId: $hints['trace_id'] ?? null,
            correlationId: $hints['correlation_id'] ?? null,
        );
        if ($eventSeq !== null && $jobId instanceof JobId) {
            // §6.6: track the job's most recent sequenced message so
            // session.jobs can report last_event_seq.
            $job = $this->jobs->tryGet($jobId);
            if ($job instanceof Job) {
                $job->lastEventSeq = $eventSeq;
            }
        }
        $logEnv = $redactedPayload === $payload ? $env : $env->withPayload($redactedPayload);
        // §6.3: while parked (or once the transport dropped) there is no
        // live connection; buffer sequenced messages for resume replay and
        // keep fanning them out to subscribers on other sessions.
        if ($session->state === SessionState::Parked || $session->transport->isClosed()) {
            if ($this->isBuffered($payload)) {
                $this->eventLog->append($logEnv);
                $this->subscriptions->dispatch($logEnv);
            }
            return $id;
        }
        // Send first: only record the envelope in the event log and fan it
        // out to subscribers once the originating transport accepted it, so a
        // failed send never leaks a "sent" envelope into replay or to
        // observers.
        try {
            $session->transport->send($env);
        } catch (\Throwable $e) {
            $this->logger->warning('emit failed; transport send threw', [
                'message_id' => (string) $id,
                'type' => $payload::typeName(),
                'error' => $e->getMessage(),
            ]);
            return $id;
        }
        if ($this->isBuffered($payload)) {
            $this->eventLog->append($logEnv);
            $this->subscriptions->dispatch($logEnv);
        }
        return $id;
    }

    /**
     * §6.4/§6.5: heartbeats and acks are session control traffic — they
     * are neither sequenced nor appended to the event log / resume
     * buffer. Everything else outbound is recorded.
     */
    private function isBuffered(MessageType $payload): bool
    {
        return !$payload instanceof SessionPing
            && !$payload instanceof SessionPong
            && !$payload instanceof SessionAck;
    }

    /**
     * §5/§8.3: sequenced messages — job events and job results — carry the
     * session-scoped monotonically increasing `event_seq`. Session control
     * messages (heartbeats, acks, handshake) are NOT sequenced.
     */
    private function isSequenced(MessageType $payload): bool
    {
        return $payload instanceof JobEvent
            || $payload instanceof JobResult
            || $payload instanceof JobError;
    }

    private function redactedPayload(MessageType $payload): MessageType
    {
        if ($payload instanceof JobAccepted && $payload->credentials !== null) {
            return $payload->redacted();
        }
        if (
            $payload instanceof JobEvent
            && $payload->eventKind === 'status'
            && ($payload->body['phase'] ?? null) === 'credential_rotated'
        ) {
            return new JobEvent($payload->eventKind, $payload->ts, [...$payload->body, 'value' => '***']);
        }
        return $payload;
    }
}
