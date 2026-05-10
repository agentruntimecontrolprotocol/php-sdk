<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Symfony\Component\Uid\Ulid;

/**
 * Identifier for a durable job (RFC §10). Ordering is guaranteed within
 * a single `job_id`; cancel/resume target jobs by id.
 */
final readonly class JobId extends Id
{
    public static function random(): self
    {
        return new self('job_' . new Ulid()->toBase32());
    }
}
