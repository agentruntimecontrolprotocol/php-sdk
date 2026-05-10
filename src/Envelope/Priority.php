<?php

declare(strict_types=1);

namespace Arcp\Envelope;

/**
 * Envelope priority (RFC §6.5).
 *
 * - `low` / `normal` / `high` participate in fair-share scheduling.
 * - `critical` is reserved for messages that MUST NOT be deferred —
 *   permission requests blocking real human action, terminal job events,
 *   etc. Implementations MAY rate-limit `critical` from misbehaving clients.
 */
enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    /** Default priority per RFC §6.5 when the envelope omits the field. */
    public static function default(): self
    {
        return self::Normal;
    }

    /**
     * Numeric weight used by the runtime's outbound scheduler (higher
     * wins). Internal — not part of the wire contract.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Normal => 4,
            self::High => 8,
            self::Critical => 16,
        };
    }
}
