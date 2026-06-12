<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Amp\Cancellation;
use Arcp\Auth\AuthRouter;
use Arcp\Envelope\Envelope;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Messages\Execution\JobError;
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
 * capability negotiation, auth-router verification, and §6.3 resume:
 * a hello presenting a valid `resume_token` reattaches the parked
 * session and replays buffered events past `last_event_seq`.
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

    /**
     * Returns the session to continue serving: the given one for a fresh
     * handshake, or the reattached parked session for a §6.3 resume.
     */
    public function negotiate(Session $session, ?Cancellation $cancellation): Session
    {
        $env = $session->transport->receive($cancellation);
        if (!$env instanceof Envelope) {
            $session->state = SessionState::Closed;
            return $session;
        }
        if (!$env->payload instanceof SessionHello) {
            $this->lifecycle->sendNoSession($session, new SessionRejected(new ErrorPayload(
                'INVALID_REQUEST',
                'expected session.hello as first message',
            )), $env->id);
            $session->state = SessionState::Rejected;
            return $session;
        }
        $ctx = new SessionHelloContext($session, $env->id, $env->payload);
        $principal = $this->authenticate($ctx);
        if ($principal === null) {
            return $session;
        }
        if ($ctx->open->resumeToken !== null) {
            return $this->resumeSession($ctx, $principal);
        }
        $this->acceptSession($ctx, $principal);
        return $session;
    }

    /**
     * §6.3: reattach a parked session to this connection's transport and
     * replay buffered events with `event_seq > last_event_seq`. Unknown
     * or expired tokens — and tokens owned by another principal — are
     * answered with a correlated top-level `job.error`
     * RESUME_WINDOW_EXPIRED.
     */
    private function resumeSession(SessionHelloContext $ctx, string $principal): Session
    {
        $token = (string) $ctx->open->resumeToken;
        $parked = $this->runtime->takeResumable($token);
        if (!$parked instanceof Session || $parked->principal !== $principal) {
            if ($parked instanceof Session) {
                // Token found but presented by another principal: put the
                // session back for its legitimate owner (§6.3, §14).
                $this->runtime->parkResumable($parked);
            }
            return $this->rejectResume($ctx, 'resume token unknown or expired');
        }
        $lastEventSeq = $ctx->open->lastEventSeq ?? 0;
        if ($this->resumeGapExists($parked, $lastEventSeq)) {
            // The buffer no longer covers last_event_seq; events in the
            // gap are unrecoverable. Re-park so the owner's window still
            // applies to in-flight jobs.
            $this->runtime->parkResumable($parked);
            return $this->rejectResume($ctx, 'buffer no longer covers last_event_seq');
        }

        $parked->transport = $ctx->session->transport;
        $parked->peerInfo = $ctx->open->client;
        $parked->state = SessionState::Authenticated;
        $ctx->session->state = SessionState::Closed;
        // The §6.2 negotiated feature set is the parked session's; the
        // resume hello does not renegotiate capabilities.
        $this->sendWelcome($parked, $ctx->envId);
        foreach ($this->runtime->eventLog->replaySince($this->requireId($parked), $lastEventSeq) as $past) {
            $parked->transport->send($past);
        }
        return $parked;
    }

    /**
     * §6.3: the buffer must cover everything after `last_event_seq`. A
     * gap exists when sequenced events past the client's watermark were
     * already released and cannot be replayed.
     */
    private function resumeGapExists(Session $parked, int $lastEventSeq): bool
    {
        if ($lastEventSeq >= $parked->currentEventSeq()) {
            return false; // nothing to replay
        }
        $earliest = $this->runtime->eventLog->earliestBufferedSeq($this->requireId($parked));
        return $earliest === null || $earliest > $lastEventSeq + 1;
    }

    private function requireId(Session $session): SessionId
    {
        return $session->sessionId
            ?? throw new \LogicException('parked session has no session id');
    }

    private function rejectResume(SessionHelloContext $ctx, string $reason): Session
    {
        $this->lifecycle->sendNoSession(
            $ctx->session,
            new JobError(JobError::ERROR, new ErrorPayload(
                'RESUME_WINDOW_EXPIRED',
                $reason,
                false,
            )),
            $ctx->envId,
        );
        $ctx->session->state = SessionState::Rejected;
        return $ctx->session;
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

        $this->sendWelcome($session, $ctx->envId);
    }

    /**
     * Emit `session.welcome` (§6.2) carrying the §6.3 resume parameters.
     * The resume token rotates on every successful welcome; the previous
     * token is invalidated by overwriting it on the session.
     */
    private function sendWelcome(Session $session, MessageId $envId): void
    {
        $sessionId = $this->requireId($session);
        $capabilities = $session->capabilities ?? new Capabilities();
        $session->resumeToken = 'rt_' . bin2hex(random_bytes(16));
        $defaultRuntime = new PeerInfo(
            Version::IMPL_NAME,
            Version::IMPL_VERSION,
            trustLevel: 'trusted',
        );
        $accepted = new SessionWelcome(
            sessionId: $sessionId,
            capabilities: $capabilities,
            runtime: $this->runtimeIdentity ?? $defaultRuntime,
            resumeToken: $session->resumeToken,
            resumeWindowSec: $this->runtime->resumeWindowSec,
            heartbeatIntervalSec: $capabilities->hasFeature('heartbeat')
                ? $this->runtime->heartbeatIntervalSec
                : null,
        );
        $this->runtime->emit($session, $accepted, ['correlation_id' => $envId]);
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
