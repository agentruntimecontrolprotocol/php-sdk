<?php

declare(strict_types=1);

namespace Arcp\Runtime;

/**
 * ARCP v1.1 §7.3 — job lifecycle states. Terminal states are `success`,
 * `error`, `cancelled`, and `timed_out`; `BUDGET_EXHAUSTED` and
 * `LEASE_EXPIRED` failures terminate as `error`.
 */
enum JobState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Error = 'error';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Success, self::Error, self::Cancelled, self::TimedOut => true,
            default => false,
        };
    }
}
