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
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Errors\UnimplementedException;
use Arcp\Ids\ArtifactId;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;
use Arcp\Internal\Client\ErrorMapper;
use Arcp\Internal\Client\HumanHandlers;
use Arcp\Internal\Client\ResponseRouter;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Artifacts\ArtifactFetch;
use Arcp\Messages\Artifacts\ArtifactPut;
use Arcp\Messages\Artifacts\ArtifactRef;
use Arcp\Messages\Control\Cancel;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Control\Ping;
use Arcp\Messages\Control\Pong;
use Arcp\Messages\Execution\ToolError;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Messages\Execution\ToolResult;
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
use Arcp\Messages\Subscriptions\Unsubscribe;
use Arcp\Runtime\PendingRegistry;
use Arcp\Runtime\Session;
use Arcp\Runtime\SessionState;
use Arcp\Transport\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Client wrapper around a {@see Transport}. Performs the handshake then
 * exposes high-level commands (`invokeTool`, `subscribe`, `cancel`, …).
 *
 * The client runs a background read-loop that delegates inbound envelope
 * dispatch to {@see ResponseRouter}: correlated responses go back to their
 * `await`ing fiber, HITL/permission requests fire the configured handlers,
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
    public readonly Session $session;
    public readonly ClockInterface $clock;
    public readonly LoggerInterface $logger;

    private readonly ResponseRouter $router;
    private readonly ErrorMapper $errorMapper;

    /** @var Future<mixed>|null */
    private ?Future $readLoop = null;

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
        $this->session = new Session($transport, isClient: true);
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
        $this->errorMapper = new ErrorMapper();
        $this->router = new ResponseRouter(
            $this->session->transport,
            $this->session,
            $this->pending,
            $this->serializer,
            $this->clock,
            $this->logger,
            new HumanHandlers(
                fn (): ?HumanInputHandler => $this->humanInputHandler,
                fn (): ?PermissionHandler => $this->permissionHandler,
            ),
        );
    }

    /**
     * Send `session.open`, await `session.accepted`, and start the read-loop.
     */
    public function open(
        Auth $auth,
        PeerInfo $client,
        Capabilities $capabilities,
        ?Cancellation $cancellation = null,
    ): SessionAccepted {
        $env = $this->prepareSessionOpen($auth, $client, $capabilities);
        $this->session->state = SessionState::Opening;
        $this->session->transport->send($env);

        $accepted = $this->awaitHandshakeResponse($cancellation);
        $this->session->sessionId = $accepted->sessionId;
        $this->session->capabilities = $accepted->capabilities;
        $this->session->peerInfo = $accepted->runtime;
        $this->session->principal = $client->principal;
        $this->session->state = SessionState::Authenticated;

        $this->readLoop = async(fn () => $this->runReadLoop($cancellation));
        return $accepted;
    }

    private function prepareSessionOpen(
        Auth $auth,
        PeerInfo $client,
        Capabilities $capabilities,
    ): Envelope {
        return new Envelope(
            id: MessageId::random(),
            payload: new SessionOpen($auth, $client, $capabilities),
            timestamp: $this->clock->now(),
        );
    }

    private function awaitHandshakeResponse(?Cancellation $cancellation): SessionAccepted
    {
        $response = $this->session->transport->receive($cancellation);
        if (!$response instanceof Envelope) {
            throw new UnauthenticatedException('handshake aborted: peer closed');
        }
        $msg = $response->payload;
        if ($msg instanceof SessionUnauthenticated) {
            throw new UnauthenticatedException($msg->error->message);
        }
        if ($msg instanceof SessionRejected) {
            throw new UnimplementedException('§7', $msg->error->message);
        }
        if (!$msg instanceof SessionAccepted) {
            throw new UnauthenticatedException(
                'handshake: unexpected response ' . $response->type(),
            );
        }
        return $msg;
    }

    /**
     * Invoke a tool synchronously; returns the wire `ToolResult`/throws.
     *
     * @param array<string, mixed> $arguments
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
            throw new InvalidArgumentException('unexpected terminal: ' . $response::class);
        }
        return $response;
    }

    /**
     * Subscribe and dispatch matching `subscribe.event` payloads to a callback.
     *
     * @param array<string, mixed> $filter
     * @param \Closure(Envelope): void $onEvent Called with the unwrapped envelope.
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
            throw new InvalidArgumentException('expected subscribe.accepted');
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
        /** @var Pong $resp */
        $resp = $this->pending->awaitResponse($id, $deadlineSeconds);
        return $resp;
    }

    public function putArtifact(
        string $mediaType,
        string $bytes,
        ?int $retentionSeconds = null,
    ): ArtifactRef {
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: new ArtifactPut($mediaType, base64_encode($bytes), $retentionSeconds),
            timestamp: $this->clock->now(),
            sessionId: $this->session->sessionId,
        );
        $this->session->transport->send($env);
        /** @var ArtifactRef $resp */
        $resp = $this->pending->awaitResponse($id, 30.0);
        return $resp;
    }

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
            throw new InvalidArgumentException('expected artifact.put as fetch response');
        }
        $bytes = base64_decode($resp->data, strict: true);
        return $bytes !== false
            ? $bytes
            : throw new InvalidArgumentException('artifact data not base64');
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
                $env = $this->session->transport->receive($cancellation);
                if (!$env instanceof Envelope) {
                    break;
                }
                $this->router->handle($env);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('client read-loop ended', ['error' => $e->getMessage()]);
        } finally {
            $this->pending->failAll(new \RuntimeException('read loop ended'));
        }
    }
}
