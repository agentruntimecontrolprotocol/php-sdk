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
    /** @var array<string, int> */
    private array $sessionIdSet;

    /** @var array<string, int> */
    private array $traceIdSet;

    /** @var array<string, int> */
    private array $jobIdSet;

    /** @var array<string, int> */
    private array $streamIdSet;

    /** @var array<string, int> */
    private array $typeSet;

    public function __construct(
        public SubscriptionId $id,
        public Session $session,
        public SubscriptionFilter $filter = new SubscriptionFilter(),
    ) {
        // Precompute O(1) membership maps so matches() does not re-scan the
        // filter lists on every dispatched envelope.
        $this->sessionIdSet = array_flip($filter->sessionIds);
        $this->traceIdSet = array_flip($filter->traceIds);
        $this->jobIdSet = array_flip($filter->jobIds);
        $this->streamIdSet = array_flip($filter->streamIds);
        $this->typeSet = array_flip($filter->types);
    }

    public function matches(Envelope $env): bool
    {
        if ($this->sessionIdSet !== [] && (
            !$env->sessionId instanceof SessionId
            || !isset($this->sessionIdSet[(string) $env->sessionId])
        )) {
            return false;
        }
        if ($this->traceIdSet !== [] && (
            !$env->traceId instanceof TraceId
            || !isset($this->traceIdSet[(string) $env->traceId])
        )) {
            return false;
        }
        if ($this->jobIdSet !== [] && (
            !$env->jobId instanceof JobId
            || !isset($this->jobIdSet[(string) $env->jobId])
        )) {
            return false;
        }
        if ($this->streamIdSet !== [] && (
            !$env->streamId instanceof StreamId
            || !isset($this->streamIdSet[(string) $env->streamId])
        )) {
            return false;
        }
        if ($this->typeSet !== [] && !isset($this->typeSet[$env->type()])) {
            return false;
        }
        // An unset min_priority floor matches every priority, so adding a
        // new lower-weight Priority case never silently drops events.
        return $this->filter->minPriority === null
            || $env->priority->weight() >= $this->filter->minPriority->weight();
    }
}
