<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Ids\MessageId;
use Arcp\Messages\Session\SessionOpen;
use Arcp\Runtime\Session;

/**
 * Parameter object for the internal {@see HandshakeNegotiator} helpers
 * carrying the session under negotiation, the open envelope id, and the
 * decoded `session.open` payload.
 *
 * @internal
 */
final readonly class SessionOpenContext
{
    public function __construct(
        public Session $session,
        public MessageId $envId,
        public SessionOpen $open,
    ) {
    }
}
