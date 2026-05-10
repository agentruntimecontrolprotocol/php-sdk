<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Symfony\Component\Uid\Ulid;

/**
 * Subscription handle returned by the runtime in `subscribe.accepted`
 * (RFC §13.1).
 */
final readonly class SubscriptionId extends Id
{
    public static function random(): self
    {
        return new self('sub_' . new Ulid()->toBase32());
    }
}
