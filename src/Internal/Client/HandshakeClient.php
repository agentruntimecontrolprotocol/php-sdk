<?php

declare(strict_types=1);

namespace Arcp\Internal\Client;

use Amp\Cancellation;
use Arcp\Clock\ClockInterface;
use Arcp\Envelope\Envelope;
use Arcp\Errors\UnauthenticatedException;
use Arcp\Errors\UnimplementedException;
use Arcp\Ids\MessageId;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Session\SessionAccepted;
use Arcp\Messages\Session\SessionOpen;
use Arcp\Messages\Session\SessionRejected;
use Arcp\Messages\Session\SessionUnauthenticated;
use Arcp\Runtime\Session;

/**
 * Handles the `session.open` -> `session.accepted` send/receive for the
 * client. Extracted from {@see \Arcp\Client\ARCPClient} so the public
 * class stays under the class-size budget.
 *
 * @internal
 */
final readonly class HandshakeClient
{
    public function __construct(
        private Session $session,
        private ClockInterface $clock,
    ) {
    }

    public function prepareEnvelope(Auth $auth, PeerInfo $client, Capabilities $caps): Envelope
    {
        return new Envelope(
            id: MessageId::random(),
            payload: new SessionOpen($auth, $client, $caps),
            timestamp: $this->clock->now(),
        );
    }

    public function awaitResponse(?Cancellation $cancellation): SessionAccepted
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
}
