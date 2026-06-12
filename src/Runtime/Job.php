<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\DeferredCancellation;
use Amp\Future;
use Arcp\Envelope\Envelope;
use Arcp\Ids\JobId;
use Arcp\Messages\Human\HumanInputResponse;
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
    /** Wall-clock timestamp of the most recent terminal transition, if any. */
    public ?\DateTimeImmutable $terminatedAt = null;

    /** @var array<string, int> */
    private array $resultChunkSeq = [];

    /**
     * Mailbox for guidance delivered in response to an `interrupt`. The
     * tool handler drains it via {@see JobContext::takeInterruptResponse()}.
     *
     * @var list<HumanInputResponse>
     */
    private array $interruptResponses = [];

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

    public function deliverInterruptResponse(HumanInputResponse $response): void
    {
        $this->interruptResponses[] = $response;
    }

    /** Pop the oldest interrupt guidance, or null if none is pending. */
    public function takeInterruptResponse(): ?HumanInputResponse
    {
        return array_shift($this->interruptResponses);
    }
}
