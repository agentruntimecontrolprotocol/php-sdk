<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Symfony\Component\Uid\Ulid;

/**
 * Stable session identifier issued by the runtime in `session.welcome`
 * (RFC §8.3, §9). Preserved across `resume` (RFC §19) — for v0.1 limited
 * to the in-flight transport.
 */
final readonly class SessionId extends Id
{
    public static function random(): self
    {
        return new self('sess_' . new Ulid()->toBase32());
    }
}
