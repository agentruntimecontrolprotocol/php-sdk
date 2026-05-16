<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Auth\AuthRouter;
use Arcp\Envelope\Envelope;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\UnimplementedException;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Session\SessionAccepted;
use Arcp\Messages\Session\SessionOpen;
use Arcp\Messages\Session\SessionRejected;
use Arcp\Messages\Session\SessionUnauthenticated;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Session;
use Arcp\Runtime\SessionState;
use Arcp\Version;

/**
 * Drives the `session.open` -> `session.accepted` handshake, including
 * capability negotiation and auth-router verification.
 *
 * @internal
 */
final class HandshakeNegotiator
{
    public function __construct(
        private readonly ARCPRuntime $runtime,
        private readonly LifecycleHandler $lifecycle,
        private readonly ?AuthRouter $authRouter,
        private readonly ?PeerInfo $runtimeIdentity,
    ) {
    }

    public function negotiate(Session $session, ?\Amp\Cancellation $cancellation): void
    {
        $env = $session->transport->receive($cancellation);
        if (!$env instanceof Envelope) {
            $session->state = SessionState::Closed;
            return;
        }
        if (!$env->payload instanceof SessionOpen) {
            $this->lifecycle->sendNoSession($session, new SessionRejected(new ErrorPayload(
                'INVALID_ARGUMENT',
                'expected session.open as first message',
            )), $env->id);
            $session->state = SessionState::Rejected;
            return;
        }
        $ctx = new SessionOpenContext($session, $env->id, $env->payload);
        if (!$this->verifyCapabilities($session, $env->id, $ctx->open->capabilities)) {
            return;
        }
        $principal = $this->authenticate($ctx);
        if ($principal === null) {
            return;
        }
        $this->acceptSession($ctx, $principal);
    }

    private function verifyCapabilities(
        Session $session,
        MessageId $envId,
        Capabilities $requested,
    ): bool {
        $mismatch = $this->checkCapabilities($requested);
        if ($mismatch === null) {
            return true;
        }
        $this->lifecycle->sendNoSession(
            $session,
            new SessionRejected(new ErrorPayload('UNIMPLEMENTED', $mismatch)),
            $envId,
        );
        $session->state = SessionState::Rejected;
        return false;
    }

    /**
     * Returns the resolved principal, or null if authentication failed
     * (in which case the session state has already been moved to
     * {@see SessionState::Rejected} and a reject envelope sent).
     */
    private function authenticate(SessionOpenContext $ctx): ?string
    {
        $router = $this->authRouter;
        if (!$router instanceof AuthRouter) {
            return $this->authenticateAnonymous($ctx);
        }
        return $this->authenticateWithRouter($ctx, $router);
    }

    private function authenticateAnonymous(SessionOpenContext $ctx): ?string
    {
        $open = $ctx->open;
        // No auth router: allow `none` if anonymous capability is requested.
        if ($open->auth->scheme !== 'none' || !$open->capabilities->anonymous) {
            $this->lifecycle->sendNoSession(
                $ctx->session,
                new SessionUnauthenticated(new ErrorPayload(
                    'UNAUTHENTICATED',
                    'no auth router configured',
                )),
                $ctx->envId,
            );
            $ctx->session->state = SessionState::Rejected;
            return null;
        }
        return $open->client->principal ?? 'anonymous';
    }

    private function authenticateWithRouter(
        SessionOpenContext $ctx,
        AuthRouter $router,
    ): ?string {
        $open = $ctx->open;
        try {
            $result = $router->verify($open->auth, $open->client);
        } catch (UnimplementedException $e) {
            $this->lifecycle->sendNoSession(
                $ctx->session,
                new SessionRejected(new ErrorPayload('UNIMPLEMENTED', $e->getMessage())),
                $ctx->envId,
            );
            $ctx->session->state = SessionState::Rejected;
            return null;
        }
        if (!$result->accepted) {
            $this->lifecycle->sendNoSession(
                $ctx->session,
                new SessionUnauthenticated(new ErrorPayload(
                    'UNAUTHENTICATED',
                    $result->error ?? 'authentication failed',
                )),
                $ctx->envId,
            );
            $ctx->session->state = SessionState::Rejected;
            return null;
        }
        return $result->principal ?? 'anonymous';
    }

    private function acceptSession(SessionOpenContext $ctx, string $principal): void
    {
        $session = $ctx->session;
        $open = $ctx->open;
        $session->sessionId = SessionId::random();
        $session->principal = $principal;
        $session->peerInfo = $open->client;
        $session->capabilities = $open->capabilities;
        $session->state = SessionState::Authenticated;

        $defaultRuntime = new PeerInfo(
            Version::IMPL_KIND,
            Version::IMPL_VERSION,
            trustLevel: 'trusted',
        );
        $accepted = new SessionAccepted(
            sessionId: $session->sessionId,
            capabilities: $this->runtime->advertisedCapabilities,
            runtime: $this->runtimeIdentity ?? $defaultRuntime,
        );
        $this->runtime->emit($session, $accepted, ['correlation_id' => $ctx->envId]);
    }

    /**
     * Validate that the runtime supports every required capability the
     * client requested. Returns a human-readable mismatch message, or
     * `null` if everything is supported.
     */
    private function checkCapabilities(Capabilities $requested): ?string
    {
        $advertised = $this->runtime->advertisedCapabilities;
        if ($requested->scheduledJobs && !$advertised->scheduledJobs) {
            return 'scheduled_jobs unsupported (RFC §10.6 v0.2)';
        }
        if ($requested->agentHandoff && !$advertised->agentHandoff) {
            return 'agent_handoff unsupported (RFC §14 v0.2)';
        }
        if ($requested->checkpoints && !$advertised->checkpoints) {
            return 'checkpoints unsupported (RFC §19 v0.2)';
        }
        // Extension demands are checked once they are registered explicitly.
        return null;
    }
}
