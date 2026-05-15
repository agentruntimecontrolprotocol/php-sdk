<?php

declare(strict_types=1);

namespace Arcp\Internal\Client;

use Arcp\Client\Handlers\HumanInputHandler;
use Arcp\Client\Handlers\PermissionHandler;
use Arcp\Clock\ClockInterface;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageType;
use Arcp\Envelope\Priority;
use Arcp\Ids\MessageId;
use Arcp\Ids\SubscriptionId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Permissions\PermissionRequest;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Arcp\Runtime\PendingRegistry;
use Arcp\Runtime\Session;
use Arcp\Transport\Transport;
use Psr\Log\LoggerInterface;

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
    /** @var array<string, \Closure(Envelope): void> */
    private array $subscribers = [];

    /** @var array<string, list<Envelope>> */
    private array $pendingSubscriptionEvents = [];

    public function __construct(
        private readonly Transport $transport,
        private readonly Session $session,
        private readonly PendingRegistry $pending,
        private readonly EnvelopeSerializer $serializer,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly HumanHandlers $handlers,
    ) {
    }

    /**
     * @param \Closure(Envelope): void $onEvent
     */
    public function registerSubscriber(SubscriptionId $id, \Closure $onEvent): void
    {
        $key = (string) $id;
        $this->subscribers[$key] = $onEvent;
        $this->drainBuffered($key, $onEvent);
    }

    public function unregisterSubscriber(SubscriptionId $id): void
    {
        unset($this->subscribers[(string) $id]);
    }

    public function handle(Envelope $env): void
    {
        $msg = $env->payload;

        if (
            $env->correlationId instanceof MessageId
            && $this->pending->resolve($env->correlationId, $msg)
        ) {
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
        $sid = $env->subscriptionId;
        if (!$sid instanceof SubscriptionId) {
            return;
        }
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
        $subscriber = $this->subscribers[$key] ?? null;
        if ($subscriber !== null) {
            $this->invokeSubscriber($subscriber, $inner);
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
        $this->transport->send(new Envelope(
            id: MessageId::random(),
            payload: $payload,
            timestamp: $this->clock->now(),
            priority: $priority,
            sessionId: $this->session->sessionId,
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
                $this->logger->warning(
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
            $this->logger->warning(
                'subscription handler error',
                ['error' => $e->getMessage()],
            );
        }
    }
}
