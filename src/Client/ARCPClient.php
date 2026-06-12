<?php

declare(strict_types=1);

namespace Arcp\Client;

use function Amp\async;

use Amp\Cancellation;
use Amp\Future;
use Arcp\Client\Handlers\HumanInputHandler;
use Arcp\Client\Handlers\PermissionHandler;
use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Errors\InvalidRequestException;
use Arcp\Errors\TransportClosedException;
use Arcp\Ids\ArtifactId;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;
use Arcp\Internal\Client\ErrorMapper;
use Arcp\Internal\Client\HandshakeClient;
use Arcp\Internal\Client\HumanHandlers;
use Arcp\Internal\Client\ResponseRouter;
use Arcp\Internal\Client\ResponseRouterDeps;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Artifacts\ArtifactFetch;
use Arcp\Messages\Artifacts\ArtifactPut;
use Arcp\Messages\Artifacts\ArtifactRef;
use Arcp\Messages\Artifacts\ArtifactRelease;
use Arcp\Messages\Control\Ack;
use Arcp\Messages\Control\Cancel;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Control\Ping;
use Arcp\Messages\Control\Pong;
use Arcp\Messages\Execution\ResultChunk;
use Arcp\Messages\Execution\ToolError;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Messages\Execution\ToolResult;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\Jobs;
use Arcp\Messages\Session\ListJobs;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Session\SessionAccepted;
use Arcp\Messages\Session\SessionClose;
use Arcp\Messages\Subscriptions\Subscribe;
use Arcp\Messages\Subscriptions\SubscribeAccepted;
use Arcp\Messages\Subscriptions\Unsubscribe;
use Arcp\Runtime\PendingRegistry;
use Arcp\Runtime\Session;
use Arcp\Runtime\SessionState;
use Arcp\Transport\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Client wrapper around a {@see Transport}. Performs the handshake then
 * exposes high-level commands (`invokeTool`, `subscribe`, `cancel`, ...).
 *
 * The client runs a background read-loop that delegates inbound envelope
 * dispatch to {@see ResponseRouter}: correlated responses go back to their
 * `await`ing fiber, permission requests fire the configured handlers,
 * and `subscribe.event` envelopes are buffered for the matching subscription.
 *
 * RFC §6.3 — every command returns either a typed response or raises a
 * typed {@see \Arcp\Errors\ARCPException}.
 */
final class ARCPClient
{
    public readonly MessageTypeRegistry $registry;
    public readonly EnvelopeSerializer $serializer;
    public readonly PendingRegistry $pending;
    public readonly ResultChunkAssembler $resultChunks;
    public readonly Session $session;
    public readonly ClockInterface $clock;
    public readonly LoggerInterface $logger;

    private readonly ResponseRouter $router;
    private readonly ErrorMapper $errorMapper;
    private readonly HandshakeClient $handshake;

    /** @var Future<mixed>|null */
    private ?Future $readLoop = null;

    /**
     * @size-check-suppress public BC; superseded by ClientConfig (use ::withConfig).
     */
    public function __construct(
        Transport $transport,
        ?MessageTypeRegistry $registry = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        public ?HumanInputHandler $humanInputHandler = null,
        public ?PermissionHandler $permissionHandler = null,
    ) {
        $this->registry = $registry ?? MessageCatalog::create();
        $this->serializer = new EnvelopeSerializer($this->registry);
        $this->pending = new PendingRegistry();
        $this->resultChunks = new ResultChunkAssembler();
        $this->session = new Session($transport, isClient: true);
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
        $this->errorMapper = new ErrorMapper();
        $this->handshake = new HandshakeClient($this->session, $this->clock);
        $this->router = new ResponseRouter(
            new ResponseRouterDeps(
                $this->session->transport,
                $this->session,
                $this->pending,
                $this->serializer,
                $this->clock,
                $this->logger,
            ),
            new HumanHandlers(
                fn (): ?HumanInputHandler => $this->humanInputHandler,
                fn (): ?PermissionHandler => $this->permissionHandler,
            ),
        );
    }

    /**
     * Construct the client from a {@see ClientConfig} parameter object.
     * Equivalent to calling the constructor with named arguments; kept
     * additively to make future BC easier.
     */
    public static function withConfig(ClientConfig $config): self
    {
        return new self(
            $config->transport,
            $config->registry,
            $config->clock,
            $config->logger,
            $config->humanInputHandler,
            $config->permissionHandler,
        );
    }

    /**
     * Send `session.open`, await `session.accepted`, and start the read-loop.
     *
     * @throws \Arcp\Errors\UnauthenticatedException when the runtime rejects credentials.
     * @throws \Arcp\Errors\InvalidRequestException for capability mismatches.
     * @throws \Arcp\Errors\ARCPExceptionInterface for other handshake errors.
     * @throws \Arcp\Errors\TransportClosedException if the transport drops.
     *
     * @size-check-suppress public BC; mirrors RFC §8.3 session.open shape.
     */
    public function open(
        Auth $auth,
        PeerInfo $client,
        Capabilities $capabilities,
        ?Cancellation $cancellation = null,
    ): SessionAccepted {
        $env = $this->handshake->prepareEnvelope($auth, $client, $capabilities);
        $this->session->state = SessionState::Opening;
        $this->session->transport->send($env);

        $accepted = $this->handshake->awaitResponse($cancellation);
        $this->session->sessionId = $accepted->sessionId;
        $this->session->capabilities = $accepted->capabilities;
        $this->session->peerInfo = $accepted->runtime;
        $this->session->principal = $client->principal;
        $this->session->state = SessionState::Authenticated;

        $this->readLoop = async(fn () => $this->runReadLoop($cancellation));
        return $accepted;
    }

    /**
     * Invoke a tool synchronously; returns the wire `ToolResult`/throws.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws \Arcp\Errors\ARCPExceptionInterface mapped from `tool.error`
     *                                             or correlated `nack` (e.g. `PermissionDeniedException`,
     *                                             `BudgetExhaustedException`, `JobNotFoundException`).
     * @throws \Arcp\Errors\TimeoutException when `deadlineSeconds`
     *                                                elapses before a terminal response arrives.
     * @throws \Arcp\Errors\CancelledException when `$cancellation` fires.
     * @throws InvalidRequestException for unexpected response shapes.
     *
     * @size-check-suppress public BC; tool.invoke options are RFC §10 wire fields.
     */
    public function invokeTool(
        string $tool,
        array $arguments = [],
        ?float $deadlineSeconds = null,
        ?TraceId $traceId = null,
        ?IdempotencyKey $idempotencyKey = null,
        ?Cancellation $cancellation = null,
    ): ToolResult {
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: new ToolInvoke($tool, $arguments),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
            traceId: $traceId ?? TraceId::random(),
            idempotencyKey: $idempotencyKey,
        );
        $this->session->transport->send($env);
        $response = $this->pending->awaitResponse($id, $deadlineSeconds, $cancellation);
        if ($response instanceof ToolError) {
            throw $this->errorMapper->raise($response->error);
        }
        if ($response instanceof Nack) {
            throw $this->errorMapper->raise($response->error);
        }
        if (!$response instanceof ToolResult) {
            throw new InvalidRequestException('unexpected terminal: ' . $response::class);
        }
        return $response;
    }

    /**
     * Subscribe and dispatch matching `subscribe.event` payloads to a callback.
     *
     * @param array<string, mixed> $filter
     * @param \Closure(Envelope): void $onEvent Called with the unwrapped envelope.
     *
     * @throws \Arcp\Errors\PermissionDeniedException when the filter
     *                                                crosses session-scope boundaries.
     * @throws \Arcp\Errors\ARCPExceptionInterface for other runtime errors.
     * @throws InvalidRequestException for unexpected response shapes.
     *
     * @size-check-suppress public BC; subscribe is the RFC §13 entry-point.
     */
    public function subscribe(
        array $filter,
        \Closure $onEvent,
        ?string $sinceMessageId = null,
        ?Cancellation $cancellation = null,
    ): SubscriptionId {
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: new Subscribe($filter, $sinceMessageId),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
        );
        $this->session->transport->send($env);
        $response = $this->pending->awaitResponse($id, 30.0, $cancellation);
        if ($response instanceof Nack) {
            throw $this->errorMapper->raise($response->error);
        }
        if (!$response instanceof SubscribeAccepted) {
            throw new InvalidRequestException('expected subscribe.accepted');
        }
        $this->router->registerSubscriber($response->subscriptionId, $onEvent);
        return $response->subscriptionId;
    }

    public function unsubscribe(SubscriptionId $id): void
    {
        $this->router->unregisterSubscriber($id);
        $env = new Envelope(
            id: MessageId::random(),
            payload: new Unsubscribe(),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
            subscriptionId: $id,
        );
        $this->session->transport->send($env);
    }

    /**
     * Page through jobs visible to this session.
     *
     * @param array<string, mixed> $filter
     *
     * @throws \Arcp\Errors\ARCPExceptionInterface for runtime errors mapped
     *                                             from a correlated `nack`.
     * @throws InvalidRequestException for unexpected response shapes.
     */
    public function listJobs(
        array $filter = [],
        int $limit = 50,
        ?string $cursor = null,
        ?Cancellation $cancellation = null,
    ): Jobs {
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: new ListJobs($filter, $limit, $cursor),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
        );
        $this->session->transport->send($env);
        $response = $this->pending->awaitResponse($id, 30.0, $cancellation);
        if ($response instanceof Nack) {
            throw $this->errorMapper->raise($response->error);
        }
        if (!$response instanceof Jobs) {
            throw new InvalidRequestException('expected session.jobs');
        }
        return $response;
    }

    public function cancelJob(
        JobId $jobId,
        string $reason = 'user_aborted',
        int $deadlineMs = 5000,
    ): void {
        $env = new Envelope(
            id: MessageId::random(),
            payload: new Cancel('job', (string) $jobId, $reason, $deadlineMs),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
        );
        $this->session->transport->send($env);
    }

    /**
     * Round-trip a ping/pong heartbeat.
     *
     * @throws \Arcp\Errors\ARCPExceptionInterface when the runtime
     *                                             returns a Nack instead of a Pong.
     * @throws InvalidRequestException for an unexpected response type.
     */
    public function ping(?string $nonce = null, float $deadlineSeconds = 5.0): Pong
    {
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: new Ping($nonce),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
        );
        $this->session->transport->send($env);
        $resp = $this->pending->awaitResponse($id, $deadlineSeconds);
        if ($resp instanceof Nack) {
            throw $this->errorMapper->raise($resp->error);
        }
        if (!$resp instanceof Pong) {
            throw new InvalidRequestException('expected pong as ping response');
        }
        return $resp;
    }

    /**
     * Upload an artifact and receive its server-issued reference.
     *
     * @param string|null $sha256 hex-encoded SHA-256 digest of `$bytes`.
     *                            When supplied, the runtime rejects the upload if the digest does
     *                            not match the decoded payload.
     *
     * @throws \Arcp\Errors\InvalidRequestException on digest mismatch or
     *                                               malformed payload.
     * @throws \Arcp\Errors\ARCPExceptionInterface on other runtime errors.
     */
    public function putArtifact(
        string $mediaType,
        string $bytes,
        ?int $retentionSeconds = null,
        ?string $sha256 = null,
    ): ArtifactRef {
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: new ArtifactPut($mediaType, base64_encode($bytes), $retentionSeconds, $sha256),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
        );
        $this->session->transport->send($env);
        $resp = $this->pending->awaitResponse($id, 30.0);
        if ($resp instanceof Nack) {
            throw $this->errorMapper->raise($resp->error);
        }
        if (!$resp instanceof ArtifactRef) {
            throw new InvalidRequestException('expected artifact.ref as put response');
        }
        return $resp;
    }

    /**
     * Fetch the bytes of an artifact owned by this session.
     *
     * @throws \Arcp\Errors\PermissionDeniedException for cross-session ids.
     * @throws \Arcp\Errors\InvalidRequestException if the artifact is
     *                                              unknown or has expired.
     * @throws \Arcp\Errors\ARCPExceptionInterface for other runtime errors.
     * @throws InvalidRequestException for malformed payload or response.
     */
    public function fetchArtifact(ArtifactId $artifactId): string
    {
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: new ArtifactFetch($artifactId),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
        );
        $this->session->transport->send($env);
        $resp = $this->pending->awaitResponse($id, 30.0);
        if ($resp instanceof Nack) {
            throw $this->errorMapper->raise($resp->error);
        }
        if (!$resp instanceof ArtifactPut) {
            throw new InvalidRequestException('expected artifact.put as fetch response');
        }
        $bytes = base64_decode($resp->data, strict: true);
        return $bytes !== false
            ? $bytes
            : throw new InvalidRequestException('artifact data not base64');
    }

    /**
     * Release an artifact owned by this session. Returns true if the
     * runtime confirmed deletion, false if it was already unknown.
     *
     * @throws \Arcp\Errors\PermissionDeniedException when the artifact
     *                                                belongs to a different session.
     * @throws \Arcp\Errors\ARCPExceptionInterface on other runtime errors.
     */
    public function releaseArtifact(ArtifactId $artifactId): bool
    {
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: new ArtifactRelease($artifactId),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
        );
        $this->session->transport->send($env);
        $resp = $this->pending->awaitResponse($id, 30.0);
        if ($resp instanceof Nack) {
            throw $this->errorMapper->raise($resp->error);
        }
        if (!$resp instanceof Ack) {
            throw new InvalidRequestException('expected ack as artifact.release response');
        }
        return $resp->note === 'released';
    }

    public function close(): void
    {
        if ($this->session->state === SessionState::Closed) {
            return;
        }
        try {
            $this->session->transport->send(new Envelope(
                id: MessageId::random(),
                payload: new SessionClose('client_close'),
                timestamp: $this->clock->now(),
                sessionId: $this->session->sessionId,
            ));
        } catch (\Throwable) {
            // peer already gone; transport will report closed below.
        }
        $this->session->transport->close();
        $this->session->state = SessionState::Closed;
        $this->pending->failAll(new \RuntimeException('client closed'));
        if ($this->readLoop instanceof Future) {
            try {
                $this->readLoop->await();
            } catch (\Throwable) {
                // already done
            }
        }
    }

    private function runReadLoop(?Cancellation $cancellation): void
    {
        try {
            while (!$this->session->transport->isClosed()) {
                if (!$this->readOnce($cancellation)) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('client read-loop ended', ['error' => $e->getMessage()]);
        } finally {
            $this->pending->failAll(new \RuntimeException('read loop ended'));
        }
    }

    /**
     * Process a single inbound frame. Returns false when the loop should
     * stop (clean EOF or transport closure). A single undecodable or
     * unknown-type frame is logged and skipped so one bad frame from the
     * peer cannot kill the session (RFC §5 forward-compatibility).
     */
    private function readOnce(?Cancellation $cancellation): bool
    {
        try {
            $env = $this->session->transport->receive($cancellation);
        } catch (TransportClosedException $e) {
            $this->logger->warning('client read-loop ended', ['error' => $e->getMessage()]);
            return false;
        } catch (InvalidRequestException $e) {
            $this->logger->warning('client dropped undecodable frame', ['error' => $e->getMessage()]);
            return true;
        }
        if (!$env instanceof Envelope) {
            return false;
        }
        if ($env->payload instanceof ResultChunk) {
            $this->resultChunks->push($env->payload);
        }
        $this->router->handle($env);
        return true;
    }
}
