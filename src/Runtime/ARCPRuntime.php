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
use Arcp\Internal\Runtime\LifecycleHandler;
use Arcp\Internal\Runtime\SubscriptionRouter;
use Arcp\Internal\Runtime\ToolInvocationHandler;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobProgress;
use Arcp\Messages\Execution\ResultChunk;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Runtime\Credentials\CredentialProvisioner;
use Arcp\Runtime\Credentials\CredentialStore;
use Arcp\Runtime\Credentials\InMemoryCredentialStore;
use Arcp\Store\EventLog;
use Arcp\Transport\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        $this->advertisedCapabilities = $capabilities ?? Capabilities::defaultRuntime();

        $lifecycle = new LifecycleHandler($this);
        $this->handshake = new HandshakeNegotiator(
            $this,
            $lifecycle,
            $authRouter,
            $this->runtimeIdentity,
        );
        $tools = new ToolInvocationHandler(
            $this,
            fn (AgentRef $ref): ?ResolvedTool => $this->resolveTool($ref),
            new CredentialLifecycle($this),
        );
        $this->dispatcher = new Dispatcher(
            $this,
            $lifecycle,
            $tools,
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
        );
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
     */
    public function serve(Transport $transport, ?Cancellation $cancellation = null): void
    {
        $session = new Session($transport, isClient: false);
        try {
            $this->handshake->negotiate($session, $cancellation);
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
            // Preserve a terminal label set during handshake/dispatch
            // (Rejected/Evicted) instead of clobbering it with Closed.
            if (!$session->state->isTerminal()) {
                $session->state = SessionState::Closed;
            }
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

    /**
     * Build, log, send, and return the message id for an outbound envelope.
     *
     * @param array{
     *     correlation_id?: MessageId,
     *     job_id?: JobId|null,
     *     stream_id?: StreamId|null,
     *     subscription_id?: SubscriptionId|null,
     *     trace_id?: TraceId|null,
     *     priority?: Priority
     * } $hints
     */
    public function emit(Session $session, MessageType $payload, array $hints = []): MessageId
    {
        $id = MessageId::random();
        $redactedPayload = $this->redactedPayload($payload);
        $env = new Envelope(
            id: $id,
            payload: $payload,
            timestamp: $this->clock->now(),
            priority: $hints['priority'] ?? Priority::Normal,
            sessionId: $session->sessionId,
            jobId: $hints['job_id'] ?? null,
            eventSeq: $this->isSequenced($payload) ? $session->nextEventSeq() : null,
            streamId: $hints['stream_id'] ?? null,
            subscriptionId: $hints['subscription_id'] ?? null,
            traceId: $hints['trace_id'] ?? null,
            correlationId: $hints['correlation_id'] ?? null,
        );
        $logEnv = $redactedPayload === $payload ? $env : $env->withPayload($redactedPayload);
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
        $this->eventLog->append($logEnv);
        $this->subscriptions->dispatch($logEnv);
        return $id;
    }

    /**
     * §5/§8.3: sequenced messages — job events and job results — carry the
     * session-scoped monotonically increasing `event_seq`. Session control
     * messages (heartbeats, acks, handshake) are NOT sequenced.
     */
    private function isSequenced(MessageType $payload): bool
    {
        return $payload instanceof JobProgress
            || $payload instanceof ResultChunk;
    }

    private function redactedPayload(MessageType $payload): MessageType
    {
        if ($payload instanceof JobAccepted && $payload->credentials !== null) {
            return $payload->redacted();
        }
        if (
            $payload instanceof EventEmit
            && $payload->eventType === 'status'
            && ($payload->attributes['phase'] ?? null) === 'credential_rotated'
        ) {
            return new EventEmit('status', [...$payload->attributes, 'value' => '***']);
        }
        return $payload;
    }
}
