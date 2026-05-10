<?php

declare(strict_types=1);

namespace Arcp\Runtime;

/** RFC §10.2 — durable job lifecycle. */
enum JobState: string
{
    case Accepted = 'accepted';
    case Queued = 'queued';
    case Running = 'running';
    case Blocked = 'blocked';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
