<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Ids\JobId;
use Arcp\Ids\SessionId;
use Arcp\Ids\StreamId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;

/**
 * Compiled subscription filter (RFC §13.2). All conditions are AND'd;
 * arrays within a field are OR'd. `min_priority` lifts to a numeric
 * weight via {@see \Arcp\Envelope\Priority::weight()}.
 */
final readonly class Subscription
{
    public function __construct(
        public SubscriptionId $id,
        public Session $session,
        public SubscriptionFilter $filter = new SubscriptionFilter(),
    ) {
    }

    public function matches(Envelope $env): bool
    {
        if ($this->filter->sessionIds !== [] && (
            !$env->sessionId instanceof SessionId
            || !\in_array((string) $env->sessionId, $this->filter->sessionIds, true)
        )) {
            return false;
        }
        if ($this->filter->traceIds !== [] && (
            !$env->traceId instanceof TraceId
            || !\in_array((string) $env->traceId, $this->filter->traceIds, true)
        )) {
            return false;
        }
        if ($this->filter->jobIds !== [] && (
            !$env->jobId instanceof JobId
            || !\in_array((string) $env->jobId, $this->filter->jobIds, true)
        )) {
            return false;
        }
        if ($this->filter->streamIds !== [] && (
            !$env->streamId instanceof StreamId
            || !\in_array((string) $env->streamId, $this->filter->streamIds, true)
        )) {
            return false;
        }
        if ($this->filter->types !== [] && !\in_array($env->type(), $this->filter->types, true)) {
            return false;
        }
        return $env->priority->weight() >= $this->filter->minPriority->weight();
    }
}
