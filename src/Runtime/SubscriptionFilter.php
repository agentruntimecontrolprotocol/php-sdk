<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Envelope\Priority;

/**
 * Compiled match-set used by {@see Subscription} (RFC §13.2). Each list is
 * OR'd internally; lists across fields are AND'd by the owning Subscription.
 */
final readonly class SubscriptionFilter
{
    /**
     * @param list<string> $sessionIds
     * @param list<string> $traceIds
     * @param list<string> $jobIds
     * @param list<string> $streamIds
     * @param list<string> $types
     *
     * @size-check-suppress parameter-object DTO mirroring RFC §13.2 filter fields.
     */
    public function __construct(
        public array $sessionIds = [],
        public array $traceIds = [],
        public array $jobIds = [],
        public array $streamIds = [],
        public array $types = [],
        public Priority $minPriority = Priority::Low,
    ) {
    }
}
