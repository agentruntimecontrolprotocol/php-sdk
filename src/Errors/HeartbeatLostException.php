<?php

declare(strict_types=1);

namespace Arcp\Errors;

use Arcp\Ids\JobId;

/**
 * RFC §10.3, §18.2 — a job missed `N` consecutive heartbeats. Carries the
 * job id and the number of misses so the caller can reason about recovery.
 */
final class HeartbeatLostException extends ARCPException
{
    public function __construct(
        public readonly JobId $jobId,
        public readonly int $missedCount,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('job %s missed %d heartbeats', $jobId, $missedCount),
            ['job_id' => (string) $jobId, 'missed_count' => $missedCount],
            null,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::HeartbeatLost;
    }
}
