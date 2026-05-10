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
}
