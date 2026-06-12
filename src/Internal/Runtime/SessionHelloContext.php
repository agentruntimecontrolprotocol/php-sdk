<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Ids\MessageId;
use Arcp\Messages\Session\SessionHello;
use Arcp\Runtime\Session;

/**
 * Parameter object for the internal {@see HandshakeNegotiator} helpers
 * carrying the session under negotiation, the open envelope id, and the
 * decoded `session.hello` payload.
 *
 * @internal
 */
final readonly class SessionHelloContext
{
    public function __construct(
        public Session $session,
        public MessageId $envId,
        public SessionHello $open,
    ) {
    }
}
