<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\DeferredCancellation;
use Amp\Future;
use Arcp\Envelope\Envelope;
use Arcp\Ids\JobId;
use Arcp\Messages\Permissions\LeaseGranted;

/**
 * Tracking record for an in-flight job (RFC §10). Owns the cancellation
 * source, last heartbeat sequence, and the future that resolves when the
 * tool fiber returns.
 */
final class Job
{
    public JobState $state = JobState::Accepted;
    public int $heartbeatSequence = 0;
    public readonly \DateTimeImmutable $createdAt;

    /** @var array<string, int> */
    private array $resultChunkSeq = [];

    /** @var Future<mixed>|null */
    public ?Future $future = null;

    public function __construct(
        public readonly JobId $id,
        public readonly Session $session,
        public readonly Envelope $invocation,
        public readonly DeferredCancellation $cancellation,
        public readonly string $tool,
        public readonly ?string $toolVersion = null,
        public readonly ?CostBudget $budget = null,
        public readonly ?LeaseGranted $lease = null,
    ) {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function toolRef(): string
    {
        return $this->toolVersion === null ? $this->tool : $this->tool . '@' . $this->toolVersion;
    }

    public function nextResultChunkSeq(string $resultId): int
    {
        $next = $this->resultChunkSeq[$resultId] ?? 0;
        $this->resultChunkSeq[$resultId] = $next + 1;
        return $next;
    }
}
