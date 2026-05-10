<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Symfony\Component\Uid\Ulid;

/**
 * Identifier for a stream (RFC §11). Per-stream sequence numbers and
 * ordering live under this id; backpressure is keyed on it too.
 */
final readonly class StreamId extends Id
{
    public static function random(): self
    {
        return new self('str_' . new Ulid()->toBase32());
    }
}
