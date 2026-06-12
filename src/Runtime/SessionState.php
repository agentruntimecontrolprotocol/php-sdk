<?php

declare(strict_types=1);

namespace Arcp\Runtime;

/** RFC §8 / §9 — protocol session lifecycle. */
enum SessionState: string
{
    case Opening = 'opening';
    case Challenged = 'challenged';
    case Authenticating = 'authenticating';
    case Authenticated = 'authenticated';
    case Refreshing = 'refreshing';

    /**
     * Transport gone but the session is held for the §6.3 resume window:
     * in-flight jobs keep running and sequenced events are buffered for
     * replay until the client reattaches or the window expires.
     */
    case Parked = 'parked';
    case Closing = 'closing';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Evicted = 'evicted';

    /** Whether this is a terminal state that must not be overwritten. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Closed, self::Rejected, self::Evicted => true,
            default => false,
        };
    }
}
