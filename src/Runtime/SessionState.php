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
