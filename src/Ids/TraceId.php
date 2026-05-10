<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Symfony\Component\Uid\Ulid;

/**
 * Distributed tracing root id (RFC §17.1). Preserved across delegation
 * so multi-runtime traces remain coherent.
 */
final readonly class TraceId extends Id
{
    public static function random(): self
    {
        return new self('trace_' . new Ulid()->toBase32());
    }
}
