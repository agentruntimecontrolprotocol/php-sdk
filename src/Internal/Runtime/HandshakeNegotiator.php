<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Amp\Cancellation;
use Arcp\Auth\AuthRouter;
use Arcp\Envelope\Envelope;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Ids\SessionId;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Session\SessionHello;
use Arcp\Messages\Session\SessionRejected;
use Arcp\Messages\Session\SessionUnauthenticated;
use Arcp\Messages\Session\SessionWelcome;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Session;
use Arcp\Runtime\SessionState;
use Arcp\Version;

/**
 * Drives the `session.hello` -> `session.welcome` handshake, including
 * capability negotiation and auth-router verification.
 *
 * @internal
 */
final readonly class HandshakeNegotiator
{
    public function __construct(
        private ARCPRuntime $runtime,
        private LifecycleHandler $lifecycle,
        private ?AuthRouter $authRouter,
        private ?PeerInfo $runtimeIdentity,
    ) {
    }

    public function negotiate(Session $session, ?Cancellation $cancellation): void
    {
        $env = $session->transport->receive($cancellation);
        if (!$env instanceof Envelope) {
            $session->state = SessionState::Closed;
            return;
        }
        if (!$env->payload instanceof SessionHello) {
            $this->lifecycle->sendNoSession($session, new SessionRejected(new ErrorPayload(
                'INVALID_REQUEST',
                'expected session.hello as first message',
            )), $env->id);
            $session->state = SessionState::Rejected;
            return;
        }
        $ctx = new SessionHelloContext($session, $env->id, $env->payload);
        $principal = $this->authenticate($ctx);
        if ($principal === null) {
            return;
        }
        $this->acceptSession($ctx, $principal);
    }

    /**
     * Returns the resolved principal, or null if authentication failed
     * (in which case the session state has already been moved to
     * {@see SessionState::Rejected} and a reject envelope sent).
     */
    private function authenticate(SessionHelloContext $ctx): ?string
    {
        // §6.1/§12: only `bearer` (and this SDK's `anonymous` extension)
        // are honored; any other scheme is UNAUTHENTICATED.
        $scheme = $ctx->open->auth->scheme;
        if ($scheme !== Auth::BEARER && $scheme !== Auth::ANONYMOUS) {
            return $this->rejectUnauthenticated($ctx, 'unsupported auth scheme: ' . $scheme);
        }
        $router = $this->authRouter;
        if (!$router instanceof AuthRouter) {
            return $this->authenticateAnonymous($ctx);
        }
        return $this->authenticateWithRouter($ctx, $router);
    }

    private function rejectUnauthenticated(SessionHelloContext $ctx, string $reason): null
    {
        $this->lifecycle->sendNoSession(
            $ctx->session,
            new SessionUnauthenticated(new ErrorPayload('UNAUTHENTICATED', $reason)),
            $ctx->envId,
        );
        $ctx->session->state = SessionState::Rejected;
        return null;
    }

    private function authenticateAnonymous(SessionHelloContext $ctx): ?string
    {
        // No auth router configured: only the `anonymous` scheme can be
        // honored — there is nothing to verify a bearer token against.
        if ($ctx->open->auth->scheme !== Auth::ANONYMOUS) {
            return $this->rejectUnauthenticated($ctx, 'no auth router configured');
        }
        // Do not trust the principal supplied in the untrusted PeerInfo
        // block: without an auth router the server assigns an opaque,
        // per-session anonymous principal so a client cannot claim another
        // identity to read its jobs or replay its idempotent outcomes.
        return 'anonymous-' . bin2hex(random_bytes(16));
    }

    private function authenticateWithRouter(
        SessionHelloContext $ctx,
        AuthRouter $router,
    ): ?string {
        $open = $ctx->open;
        try {
            $result = $router->verify($open->auth, $open->client);
        } catch (UnauthenticatedException $e) {
            return $this->rejectUnauthenticated($ctx, $e->getMessage());
        }
        if (!$result->accepted) {
            return $this->rejectUnauthenticated($ctx, $result->error ?? 'authentication failed');
        }
        return $result->principal ?? 'anonymous';
    }

    private function acceptSession(SessionHelloContext $ctx, string $principal): void
    {
        $session = $ctx->session;
        $open = $ctx->open;
        $session->sessionId = SessionId::random();
        $session->principal = $principal;
        $session->peerInfo = $open->client;
        $acceptedCapabilities = $this->acceptedCapabilities($open->capabilities);
        $session->capabilities = $acceptedCapabilities;
        $session->state = SessionState::Authenticated;

        $defaultRuntime = new PeerInfo(
            Version::IMPL_NAME,
            Version::IMPL_VERSION,
            trustLevel: 'trusted',
        );
        $accepted = new SessionWelcome(
            sessionId: $session->sessionId,
            capabilities: $acceptedCapabilities,
            runtime: $this->runtimeIdentity ?? $defaultRuntime,
        );
        $this->runtime->emit($session, $accepted, ['correlation_id' => $ctx->envId]);
    }

    /**
     * §6.2 intersection semantics: the effective feature set is the
     * intersection of the hello and welcome features. Features the
     * runtime does not back are silently absent from the welcome; the
     * client MUST NOT use them.
     */
    private function acceptedCapabilities(Capabilities $requested): Capabilities
    {
        return $this->runtime->advertisedCapabilitiesForSession()->intersect($requested);
    }
}
