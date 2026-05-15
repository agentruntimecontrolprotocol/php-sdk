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
use Arcp\Envelope\Priority;
use Arcp\Errors\AbortedException;
use Arcp\Errors\AlreadyExistsException;
use Arcp\Errors\ARCPException;
use Arcp\Errors\BackpressureOverflowException;
use Arcp\Errors\CancelledException;
use Arcp\Errors\DataLossException;
use Arcp\Errors\DeadlineExceededException;
use Arcp\Errors\ErrorCode;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\FailedPreconditionException;
use Arcp\Errors\InternalException;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\NotFoundException;
use Arcp\Errors\OutOfRangeException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Errors\ResourceExhaustedException;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Errors\UnavailableException;
use Arcp\Errors\UnimplementedException;
use Arcp\Errors\UnknownException;
use Arcp\Ids\ArtifactId;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;
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
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Permissions\PermissionRequest;
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
use Arcp\Messages\Subscriptions\SubscribeEvent;
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
 * The client runs a background read-loop that:
 *   - Routes correlated responses back to their `await`ing fiber.
 *   - Invokes user-supplied handlers for HITL / permission requests.
 *   - Buffers `subscribe.event` envelopes for the matching subscription.
 *
 * RFC §6.3 — every command returns either a typed response or raises a
 * typed {@see ARCPException}.
 */
final class ARCPClient
{
    public readonly MessageTypeRegistry $registry;
    public readonly EnvelopeSerializer $serializer;
    public readonly PendingRegistry $pending;
    public readonly Session $session;
    public readonly ClockInterface $clock;
    public readonly LoggerInterface $logger;

    /** @var array<string, \Closure(Envelope): void> */
    private array $subscribers = [];

    /**
     * Subscription ids for which we've received SubscribeEvents before the
     * caller's subscribe() returned. Drained when the callback is set.
     *
     * @var array<string, list<Envelope>>
     */
    private array $pendingSubscriptionEvents = [];

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
        $open = new SessionOpen($auth, $client, $capabilities);
        $id = MessageId::random();
        $env = new Envelope(
            id: $id,
            payload: $open,
            timestamp: $this->clock->now(),
        );
        $this->session->state = SessionState::Opening;
        $this->session->transport->send($env);

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
        $this->session->sessionId = $msg->sessionId;
        $this->session->capabilities = $msg->capabilities;
        $this->session->peerInfo = $msg->runtime;
        $this->session->principal = $client->principal;
        $this->session->state = SessionState::Authenticated;

        $this->readLoop = async(fn () => $this->runReadLoop($cancellation));
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
            throw $this->raise($response->error);
        }
        if ($response instanceof Nack) {
            throw $this->raise($response->error);
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
            throw $this->raise($response->error);
        }
        if (!$response instanceof SubscribeAccepted) {
            throw new InvalidArgumentException('expected subscribe.accepted');
        }
        $key = (string) $response->subscriptionId;
        $this->subscribers[$key] = $onEvent;
        // Drain any events that arrived before this point.
        if (isset($this->pendingSubscriptionEvents[$key])) {
            foreach ($this->pendingSubscriptionEvents[$key] as $bufferedEnv) {
                try {
                    $onEvent($bufferedEnv);
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'subscription callback error during drain',
                        ['error' => $e->getMessage()],
                    );
                }
            }
            unset($this->pendingSubscriptionEvents[$key]);
        }
        return $response->subscriptionId;
    }

    public function unsubscribe(SubscriptionId $id): void
    {
        unset($this->subscribers[(string) $id]);
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
            throw $this->raise($resp->error);
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
                $this->handle($env);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('client read-loop ended', ['error' => $e->getMessage()]);
        } finally {
            $this->pending->failAll(new \RuntimeException('read loop ended'));
        }
    }

    private function handle(Envelope $env): void
    {
        $msg = $env->payload;

        if (
            $env->correlationId instanceof MessageId
            && $this->pending->resolve($env->correlationId, $msg)
        ) {
            return;
        }

        if ($msg instanceof SubscribeEvent) {
            $sid = $env->subscriptionId;
            if ($sid instanceof SubscriptionId) {
                $key = (string) $sid;
                try {
                    $inner = $this->serializer->envelopeFromArray($msg->event);
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'subscription decode error',
                        ['error' => $e->getMessage()],
                    );
                    return;
                }
                if (isset($this->subscribers[$key])) {
                    try {
                        ($this->subscribers[$key])($inner);
                    } catch (\Throwable $e) {
                        $this->logger->warning(
                            'subscription handler error',
                            ['error' => $e->getMessage()],
                        );
                    }
                } else {
                    $this->pendingSubscriptionEvents[$key][] = $inner;
                }
            }
            return;
        }

        if (
            $msg instanceof HumanInputRequest
            && $this->humanInputHandler instanceof HumanInputHandler
        ) {
            $response = $this->humanInputHandler->onInputRequest($msg);
            $this->session->transport->send(new Envelope(
                id: MessageId::random(),
                payload: $response,
                timestamp: $this->clock->now(),
                priority: Priority::High,
                sessionId: $this->session->sessionId,
                jobId: $env->jobId,
                traceId: $env->traceId,
                correlationId: $env->id,
            ));
            return;
        }

        if (
            $msg instanceof HumanChoiceRequest
            && $this->humanInputHandler instanceof HumanInputHandler
        ) {
            $response = $this->humanInputHandler->onChoiceRequest($msg);
            $this->session->transport->send(new Envelope(
                id: MessageId::random(),
                payload: $response,
                timestamp: $this->clock->now(),
                priority: Priority::High,
                sessionId: $this->session->sessionId,
                jobId: $env->jobId,
                traceId: $env->traceId,
                correlationId: $env->id,
            ));
            return;
        }

        if (
            $msg instanceof PermissionRequest
            && $this->permissionHandler instanceof PermissionHandler
        ) {
            $decision = $this->permissionHandler->onPermissionRequest($msg);
            $this->session->transport->send(new Envelope(
                id: MessageId::random(),
                payload: $decision,
                timestamp: $this->clock->now(),
                priority: Priority::Critical,
                sessionId: $this->session->sessionId,
                jobId: $env->jobId,
                traceId: $env->traceId,
                correlationId: $env->id,
            ));
            return;
        }

        // Job/stream events with no waiter: clients may attach watchers
        // through subscriptions or peeking at the runtime event log.
    }

    private function raise(ErrorPayload $err): ARCPException
    {
        $canonical = $err->canonical();
        $perm = $err->details['permission'] ?? '?';
        $res  = $err->details['resource'] ?? '?';
        return match ($canonical) {
            ErrorCode::PermissionDenied => new PermissionDeniedException(
                \is_string($perm) ? $perm : '?',
                \is_string($res) ? $res : '?',
                $err->message,
            ),
            ErrorCode::Unimplemented => new UnimplementedException('?', $err->message),
            ErrorCode::DeadlineExceeded => new DeadlineExceededException($err->message),
            ErrorCode::Cancelled => new CancelledException($err->message),
            ErrorCode::NotFound => new NotFoundException($err->message),
            ErrorCode::AlreadyExists => new AlreadyExistsException($err->message),
            ErrorCode::ResourceExhausted => new ResourceExhaustedException($err->message),
            ErrorCode::FailedPrecondition => new FailedPreconditionException($err->message),
            ErrorCode::InvalidArgument => new InvalidArgumentException($err->message),
            ErrorCode::Internal => new InternalException($err->message),
            ErrorCode::Unavailable => new UnavailableException($err->message),
            ErrorCode::DataLoss => new DataLossException($err->message),
            ErrorCode::Unauthenticated => new UnauthenticatedException($err->message),
            ErrorCode::Aborted => new AbortedException($err->message),
            ErrorCode::OutOfRange => new OutOfRangeException($err->message),
            ErrorCode::BackpressureOverflow => new BackpressureOverflowException($err->message),
            default => new UnknownException($err->code, $err->message),
        };
    }
}
