<?php

declare(strict_types=1);

namespace Arcp\Internal\Client;

use Arcp\Client\Handlers\HumanInputHandler;
use Arcp\Client\Handlers\PermissionHandler;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageType;
use Arcp\Envelope\Priority;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Permissions\PermissionRequest;
use Arcp\Messages\Session\SessionPing;
use Arcp\Messages\Session\SessionPong;
use Arcp\Messages\Subscriptions\SubscribeEvent;

/**
 * Dispatches inbound envelopes for {@see \Arcp\Client\ARCPClient}.
 *
 * Owns the subscription buffer and routes human/permission interaction
 * requests so the client class stays close to its public command surface.
 *
 * @internal
 */
final class ResponseRouter
{
    /**
     * Upper bound on events buffered for a not-yet-registered job
     * subscription, so a never-registered (racy or bogus) job id cannot
     * grow the buffer without limit.
     */
    private const int MAX_PENDING_SUBSCRIPTION_EVENTS = 1024;

    /** @var array<string, \Closure(Envelope): void> */
    private array $subscribers = [];

    /** @var array<string, list<Envelope>> */
    private array $pendingSubscriptionEvents = [];

    public function __construct(
        private readonly ResponseRouterDeps $deps,
        private readonly HumanHandlers $handlers,
    ) {
    }

    /**
     * @param \Closure(Envelope): void $onEvent
     */
    public function registerSubscriber(JobId $jobId, \Closure $onEvent): void
    {
        $key = (string) $jobId;
        $this->subscribers[$key] = $onEvent;
        $this->drainBuffered($key, $onEvent);
    }

    public function unregisterSubscriber(JobId $jobId): void
    {
        $key = (string) $jobId;
        unset($this->subscribers[$key], $this->pendingSubscriptionEvents[$key]);
    }

    public function handle(Envelope $env): void
    {
        $msg = $env->payload;

        if (
            $env->correlationId instanceof MessageId
            && $this->deps->pending->resolve($env->correlationId, $msg)
        ) {
            return;
        }
        if ($msg instanceof SessionPing) {
            // §6.4: the receiver MUST answer an inbound session.ping with a
            // correlated session.pong carrying ping_nonce and received_at,
            // independent of any configured application handlers.
            $this->sendReply(
                $env,
                new SessionPong($msg->nonce, $this->deps->clock->now()),
                Priority::High,
            );
            return;
        }
        if ($msg instanceof SubscribeEvent) {
            $this->routeSubscribeEvent($env, $msg);
            return;
        }
        if ($this->dispatchHumanRequest($env, $msg)) {
            return;
        }
        $this->dispatchPermissionRequest($env, $msg);
    }

    private function routeSubscribeEvent(Envelope $env, SubscribeEvent $msg): void
    {
        // §7.6: subscriptions are job-scoped; route on the wrapped job id.
        $jobId = $env->jobId;
        if (!$jobId instanceof JobId) {
            return;
        }
        $key = (string) $jobId;
        try {
            $inner = $this->deps->serializer->envelopeFromArray($msg->event);
        } catch (\Throwable $e) {
            $this->deps->logger->warning(
                'subscription decode error',
                ['error' => $e->getMessage()],
            );
            return;
        }
        $subscriber = $this->subscribers[$key] ?? null;
        if ($subscriber !== null) {
            $this->invokeSubscriber($subscriber, $inner);
            return;
        }
        if (\count($this->pendingSubscriptionEvents[$key] ?? []) >= self::MAX_PENDING_SUBSCRIPTION_EVENTS) {
            $this->deps->logger->warning(
                'dropping subscription event; pending buffer full for unregistered job',
                ['job_id' => $key, 'cap' => self::MAX_PENDING_SUBSCRIPTION_EVENTS],
            );
            return;
        }
        $this->pendingSubscriptionEvents[$key][] = $inner;
    }

    private function dispatchHumanRequest(Envelope $env, MessageType $msg): bool
    {
        $handler = $this->handlers->humanInput();
        if (!$handler instanceof HumanInputHandler) {
            return false;
        }
        if ($msg instanceof HumanInputRequest) {
            $this->sendReply($env, $handler->onInputRequest($msg), Priority::High);
            return true;
        }
        if ($msg instanceof HumanChoiceRequest) {
            $this->sendReply($env, $handler->onChoiceRequest($msg), Priority::High);
            return true;
        }
        return false;
    }

    private function dispatchPermissionRequest(Envelope $env, MessageType $msg): void
    {
        $handler = $this->handlers->permission();
        if (!$msg instanceof PermissionRequest || !$handler instanceof PermissionHandler) {
            return;
        }
        $decision = $handler->onPermissionRequest($msg);
        $this->sendReply($env, $decision, Priority::Critical);
    }

    private function sendReply(Envelope $inbound, MessageType $payload, Priority $priority): void
    {
        $this->deps->transport->send(new Envelope(
            id: MessageId::random(),
            payload: $payload,
            timestamp: $this->deps->clock->now(),
            priority: $priority,
            sessionId: $this->deps->session->sessionId,
            jobId: $inbound->jobId,
            traceId: $inbound->traceId,
            correlationId: $inbound->id,
        ));
    }

    /**
     * @param \Closure(Envelope): void $onEvent
     */
    private function drainBuffered(string $key, \Closure $onEvent): void
    {
        if (!isset($this->pendingSubscriptionEvents[$key])) {
            return;
        }
        foreach ($this->pendingSubscriptionEvents[$key] as $bufferedEnv) {
            try {
                $onEvent($bufferedEnv);
            } catch (\Throwable $e) {
                $this->deps->logger->warning(
                    'subscription callback error during drain',
                    ['error' => $e->getMessage()],
                );
            }
        }
        unset($this->pendingSubscriptionEvents[$key]);
    }

    /**
     * @param \Closure(Envelope): void $subscriber
     */
    private function invokeSubscriber(\Closure $subscriber, Envelope $inner): void
    {
        try {
            $subscriber($inner);
        } catch (\Throwable $e) {
            $this->deps->logger->warning(
                'subscription handler error',
                ['error' => $e->getMessage()],
            );
        }
    }
}
