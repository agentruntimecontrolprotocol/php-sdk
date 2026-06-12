<?php

declare(strict_types=1);

namespace Arcp\Store;

/**
 * One §7.2 idempotency-cache row.
 *
 * Claimed at job acceptance: `(principal, idempotency_key)` maps to the
 * canonical request `fingerprint`, the original `job.accepted` message
 * id (replayed verbatim on an identical retry), and — once the job
 * terminates — the terminal outcome message id.
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $principal,
        public string $idempotencyKey,
        public string $fingerprint,
        public string $acceptedMessageId,
        public ?string $outcomeMessageId,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
