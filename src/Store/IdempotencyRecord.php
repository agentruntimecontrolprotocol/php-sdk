<?php

declare(strict_types=1);

namespace Arcp\Store;

/**
 * Parameter object for {@see EventLog::rememberIdempotent()}.
 *
 * Bundles the four-field `(principal, idempotency_key, outcome, expires_at)`
 * cache row so the call site stays under the parameter-count budget.
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $principal,
        public string $idempotencyKey,
        public string $outcomeMessageId,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
