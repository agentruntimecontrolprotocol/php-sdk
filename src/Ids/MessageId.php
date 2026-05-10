<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Symfony\Component\Uid\Ulid;

/**
 * Globally unique message id (RFC §6.1, §6.4) — also the transport-level
 * idempotency key. Receivers MUST dedupe by this value.
 */
final readonly class MessageId extends Id
{
    public static function random(): self
    {
        return new self('msg_' . new Ulid()->toBase32());
    }
}
