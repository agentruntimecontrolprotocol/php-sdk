<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Amp\Cancellation;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageType;
use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\InternalException;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\TransportClosedException;
use Arcp\Errors\UnimplementedException;
use Arcp\Ids\MessageId;
use Arcp\Messages\Artifacts\ArtifactFetch;
use Arcp\Messages\Artifacts\ArtifactPut;
use Arcp\Messages\Artifacts\ArtifactRelease;
use Arcp\Messages\Control\Ack;
use Arcp\Messages\Control\Cancel;
use Arcp\Messages\Control\Interrupt;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Control\Ping;
use Arcp\Messages\Control\Pong;
use Arcp\Messages\Control\Resume;
use Arcp\Messages\Execution\AgentDelegate;
use Arcp\Messages\Execution\AgentHandoff;
use Arcp\Messages\Execution\JobSchedule;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Messages\Execution\WorkflowStart;
use Arcp\Messages\Permissions\LeaseRefresh;
use Arcp\Messages\Session\ListJobs;
use Arcp\Messages\Session\SessionClose;
use Arcp\Messages\Subscriptions\Subscribe;
use Arcp\Messages\Subscriptions\Unsubscribe;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Session;

/**
 * Owns the post-handshake read loop and the dispatch switch. Each branch
 * is delegated to one of the focused collaborators.
 *
 * @internal
 */
final readonly class Dispatcher
{
    public function __construct(
        private ARCPRuntime $runtime,
        private LifecycleHandler $lifecycle,
        private ToolInvocationHandler $toolInvocation,
        private SubscriptionRouter $subscriptions,
        private ArtifactDispatcher $artifacts,
        private JobListHandler $jobList,
    ) {
    }

    public function readLoop(Session $session, ?Cancellation $cancellation): void
    {
        while (!$session->transport->isClosed()) {
            try {
                $env = $session->transport->receive($cancellation);
            } catch (TransportClosedException) {
                return;
            } catch (InvalidArgumentException | UnimplementedException $e) {
                // A single undecodable or unknown-type frame must not tear
                // down the session (RFC §5 forward-compatibility); log and
                // keep reading so subsequent valid commands still work.
                $this->runtime->logger->warning(
                    'dropped undecodable frame',
                    ['error' => $e->getMessage()],
                );
                continue;
            }
            if (!$env instanceof Envelope) {
                return;
            }
            if (!$this->shouldDispatch($env)) {
                continue;
            }
            $this->runtime->subscriptions->dispatch($env);
            $this->dispatchOne($session, $env);
        }
    }

    /**
     * Append to the event log and return false when the envelope is a
     * duplicate that should be skipped (RFC §6.4 transport idempotency).
     */
    private function shouldDispatch(Envelope $env): bool
    {
        try {
            // Inbound (client→runtime) envelope: recorded for dedup/audit but
            // excluded from resume/backfill replay (RFC §6.3).
            return $this->runtime->eventLog->append($env, outbound: false);
        } catch (\Throwable $e) {
            $this->runtime->logger->warning(
                'event log append failed',
                ['error' => $e->getMessage()],
            );
            return true;
        }
    }

    private function dispatchOne(Session $session, Envelope $env): void
    {
        try {
            $this->dispatch($session, $env);
        } catch (\Throwable $e) {
            $this->runtime->logger->warning(
                'dispatch failed',
                ['type' => $env->type(), 'error' => $e->getMessage()],
            );
            if (!($e instanceof ARCPException)) {
                $e = new InternalException($e->getMessage(), [], null, $e);
            }
            $this->runtime->emit($session, new Nack(ErrorPayload::fromException($e)), [
                'correlation_id' => $env->id,
            ]);
        }
    }

    private function dispatch(Session $session, Envelope $env): void
    {
        $msg = $env->payload;

        // Pending await routing: any envelope whose correlation id matches
        // an outstanding waiter is delivered there first (RFC §6.3).
        if (
            $env->correlationId instanceof MessageId
            && $this->runtime->pending->resolve($env->correlationId, $msg)
        ) {
            return;
        }
        if ($this->routeLifecycle($session, $env, $msg)) {
            return;
        }
        if ($this->routeWork($session, $env, $msg)) {
            return;
        }
        $this->routeFallback($session, $env, $msg);
    }

    private function routeLifecycle(Session $session, Envelope $env, MessageType $msg): bool
    {
        match (true) {
            $msg instanceof Ping => $this->lifecycle->handlePing($session, $env, $msg),
            $msg instanceof Pong, $msg instanceof Ack => null,
            $msg instanceof SessionClose => $this->lifecycle->handleSessionClose($session),
            $msg instanceof Cancel => $this->lifecycle->handleCancel($session, $env, $msg),
            $msg instanceof Interrupt => $this->lifecycle->handleInterrupt($session, $env, $msg),
            $msg instanceof Resume => $this->lifecycle->handleResume($session, $env, $msg),
            $msg instanceof LeaseRefresh
                => $this->lifecycle->handleLeaseRefresh($session, $env, $msg),
            default => null,
        };
        return $msg instanceof Ping
            || $msg instanceof Pong
            || $msg instanceof Ack
            || $msg instanceof SessionClose
            || $msg instanceof Cancel
            || $msg instanceof Interrupt
            || $msg instanceof Resume
            || $msg instanceof LeaseRefresh;
    }

    private function routeWork(Session $session, Envelope $env, MessageType $msg): bool
    {
        // §6.2: a feature-flagged surface may only be used when it is in the
        // negotiated intersection. Reject un-negotiated list_jobs/subscribe.
        if ($msg instanceof ListJobs && !$this->featureEnabled($session, 'list_jobs')) {
            $this->lifecycle->nack($session, $env, 'UNIMPLEMENTED', 'list_jobs not negotiated');
            return true;
        }
        if ($msg instanceof Subscribe && !$this->featureEnabled($session, 'subscribe')) {
            $this->lifecycle->nack($session, $env, 'UNIMPLEMENTED', 'subscribe not negotiated');
            return true;
        }
        match (true) {
            $msg instanceof ToolInvoke => $this->toolInvocation->handle($session, $env, $msg),
            $msg instanceof ListJobs => $this->jobList->handle($session, $env, $msg),
            $msg instanceof Subscribe => $this->subscriptions->subscribe($session, $env, $msg),
            $msg instanceof Unsubscribe => $this->subscriptions->unsubscribe($session, $env),
            $msg instanceof ArtifactPut => $this->artifacts->put($session, $env, $msg),
            $msg instanceof ArtifactFetch => $this->artifacts->fetch($session, $env, $msg),
            $msg instanceof ArtifactRelease => $this->artifacts->release($session, $env, $msg),
            default => null,
        };
        return $msg instanceof ToolInvoke
            || $msg instanceof ListJobs
            || $msg instanceof Subscribe
            || $msg instanceof Unsubscribe
            || $msg instanceof ArtifactPut
            || $msg instanceof ArtifactFetch
            || $msg instanceof ArtifactRelease;
    }

    private function featureEnabled(Session $session, string $feature): bool
    {
        return $session->capabilities !== null
            && \in_array($feature, $session->capabilities->features, true);
    }

    private function routeFallback(Session $session, Envelope $env, MessageType $msg): void
    {
        if (
            $msg instanceof JobSchedule
            || $msg instanceof WorkflowStart
            || $msg instanceof AgentDelegate
            || $msg instanceof AgentHandoff
        ) {
            $this->lifecycle->nack($session, $env, 'UNIMPLEMENTED', 'feature deferred to v0.2');
            return;
        }
        // Pong, ack, etc. with no waiter: silently drop.
        $this->runtime->logger->debug('unhandled message', ['type' => $msg::class]);
    }
}
