<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\DeferredCancellation;
use Amp\Future;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\TraceId;

/**
 * Tracking record for an in-flight job (RFC §10). Owns the cancellation
 * source, last heartbeat sequence, and the future that resolves when the
 * tool fiber returns.
 */
final class Job
{
    public JobState $state = JobState::Accepted;
    public int $heartbeatSequence = 0;

    /** @var Future<mixed>|null */
    public ?Future $future = null;

    public function __construct(
        public readonly JobId $id,
        public readonly Session $session,
        public readonly MessageId $invocationId,
        public readonly ?TraceId $traceId,
        public readonly DeferredCancellation $cancellation,
        public readonly string $tool,
    ) {
    }
}
