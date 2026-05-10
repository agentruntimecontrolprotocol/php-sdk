<?php

declare(strict_types=1);

namespace Arcp\Ids;

/**
 * Logical idempotency key for a command intent (RFC §6.4) — distinct
 * from the transport-level message `id`. Survives reconnects: a client
 * retrying "create refund for order 4812" reuses this key even when a
 * fresh `id` is generated.
 *
 * No `random()` factory: the key is always provided by the caller, never
 * synthesized by the runtime.
 */
final readonly class IdempotencyKey extends Id
{
}
