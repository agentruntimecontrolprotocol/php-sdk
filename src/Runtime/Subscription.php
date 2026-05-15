<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Envelope\Priority;
use Arcp\Ids\SubscriptionId;

/**
 * Compiled subscription filter (RFC §13.2). All conditions are AND'd;
 * arrays within a field are OR'd. `min_priority` lifts to a numeric
 * weight via {@see Priority::weight()}.
 */
final readonly class Subscription
{
    /**
     * @param list<string> $sessionIds
     * @param list<string> $traceIds
     * @param list<string> $jobIds
     * @param list<string> $streamIds
     * @param list<string> $types
     */
    public function __construct(
        public SubscriptionId $id,
        public Session $session,
        public array $sessionIds = [],
        public array $traceIds = [],
        public array $jobIds = [],
        public array $streamIds = [],
        public array $types = [],
        public Priority $minPriority = Priority::Low,
    ) {
    }

    public function matches(Envelope $env): bool
    {
        if ($this->sessionIds !== [] && (
            $env->sessionId === null || !\in_array((string) $env->sessionId, $this->sessionIds, true)
        )) {
            return false;
        }
        if ($this->traceIds !== [] && (
            $env->traceId === null || !\in_array((string) $env->traceId, $this->traceIds, true)
        )) {
            return false;
        }
        if ($this->jobIds !== [] && (
            $env->jobId === null || !\in_array((string) $env->jobId, $this->jobIds, true)
        )) {
            return false;
        }
        if ($this->streamIds !== [] && (
            $env->streamId === null || !\in_array((string) $env->streamId, $this->streamIds, true)
        )) {
            return false;
        }
        if ($this->types !== [] && !\in_array($env->type(), $this->types, true)) {
            return false;
        }
        if ($env->priority->weight() < $this->minPriority->weight()) {
            return false;
        }
        return true;
    }
}
