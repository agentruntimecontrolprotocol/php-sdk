<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Symfony\Component\Uid\Ulid;

/**
 * Identifier for a permission lease (RFC §15.5). The lease lifecycle —
 * granted / extended / revoked / expired — keys on this id.
 */
final readonly class LeaseId extends Id
{
    public static function random(): self
    {
        return new self('lease_' . new Ulid()->toBase32());
    }
}
