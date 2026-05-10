<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Symfony\Component\Uid\Ulid;

/**
 * Span id within a trace tree (RFC §17.1). 16 hex digits is conventional
 * but ARCP only mandates an opaque non-empty string.
 */
final readonly class SpanId extends Id
{
    public static function random(): self
    {
        return new self('span_' . new Ulid()->toBase32());
    }
}
