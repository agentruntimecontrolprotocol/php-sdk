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
    public JobState $state = JobState::Pending;

    /**
     * True once cancellation has been requested (§7.4). The state stays
     * `running` until the cooperating fiber unwinds to the `cancelled`
     * terminal; §7.3 defines no intermediate cancelling state.
     */
    public bool $cancelRequested = false;

    /**
     * True when the cancellation was triggered by `max_runtime_sec`
     * expiry, so the terminal is `timed_out`/TIMEOUT instead of
     * `cancelled`/CANCELLED (§7.3).
     */
    public bool $timedOut = false;

    /** Event-loop timer id enforcing `max_runtime_sec`, when armed. */
    public ?string $runtimeTimerId = null;

    /**
     * `event_seq` of this job's most recent sequenced message (§6.6
     * `last_event_seq`); null until the first job event is emitted.
     */
    public ?int $lastEventSeq = null;
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
        ?\DateTimeImmutable $createdAt = null,
        public readonly ?JobId $parentJobId = null,
    ) {
        $this->createdAt = $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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
