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
use Arcp\Extensions\ExtensionRegistry;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\StreamId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;
use Arcp\Internal\Runtime\ArtifactDispatcher;
use Arcp\Internal\Runtime\Dispatcher;
use Arcp\Internal\Runtime\HandshakeNegotiator;
use Arcp\Internal\Runtime\LifecycleHandler;
use Arcp\Internal\Runtime\SubscriptionRouter;
use Arcp\Internal\Runtime\ToolInvocationHandler;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Store\EventLog;
use Arcp\Transport\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Server-side runtime. Owns sessions, jobs, streams, subscriptions,
 * leases, artifacts, and the event log. The complete lifecycle from
 * `session.open` through `session.close` flows through {@see serve()}.
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
    public readonly ClockInterface $clock;
    public readonly LoggerInterface $logger;
    public readonly Capabilities $advertisedCapabilities;

    /** @var array<string, ToolHandler> */
    private array $tools = [];

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

        $lifecycle = new LifecycleHandler($this);
        $this->handshake = new HandshakeNegotiator(
            $this,
            $lifecycle,
            $authRouter,
            $this->runtimeIdentity,
        );
        $tools = new ToolInvocationHandler(
            $this,
            fn (string $name): ?ToolHandler => $this->tools[$name] ?? null,
        );
        $this->dispatcher = new Dispatcher(
            $this,
            $lifecycle,
            $tools,
            new SubscriptionRouter($this, $lifecycle),
            new ArtifactDispatcher($this, $lifecycle),
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
        );
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
}
