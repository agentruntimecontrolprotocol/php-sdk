<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\JobNotFoundException;
use Arcp\Ids\JobId;
use Arcp\Messages\Permissions\LeaseGranted;

/**
 * Tracks in-flight jobs and drives the §7.3 state machine. Each job
 * runs in its own fiber spawned by
 * {@see \Arcp\Internal\Runtime\JobSubmitHandler} via `Amp\async()`;
 * cancellation is cooperative through the per-job {@see DeferredCancellation}.
 */
final class JobManager
{
    /** @var array<string, Job> */
    private array $jobs = [];

    /**
     * @param int $terminalRetentionSeconds how long terminal jobs remain
     *                                      visible to `session.list_jobs`. Operators rely on the list path
     *                                      to reconstruct recent history; we keep terminal entries around
     *                                      for a bounded window so cursors keep working past completion.
     */
    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
        public readonly int $terminalRetentionSeconds = 900,
    ) {
    }

    public function start(
        Session $session,
        Envelope $invocation,
        string $tool,
        ?string $toolVersion = null,
        ?CostBudget $budget = null,
        ?LeaseGranted $lease = null,
    ): Job {
        $job = new Job(
            id: JobId::random(),
            session: $session,
            invocation: $invocation,
            cancellation: new DeferredCancellation(),
            tool: $tool,
            toolVersion: $toolVersion,
            budget: $budget,
            lease: $lease,
            createdAt: $this->clock->now(),
        );
        $this->jobs[(string) $job->id] = $job;
        return $job;
    }

    public function get(JobId $id): Job
    {
        return $this->jobs[(string) $id]
            ?? throw new JobNotFoundException(\sprintf('job %s not found', $id));
    }

    public function tryGet(JobId $id): ?Job
    {
        return $this->jobs[(string) $id] ?? null;
    }

    public function transition(Job $job, JobState $next): void
    {
        $job->state = $next;
        if ($next->isTerminal()) {
            $job->terminatedAt = $this->clock->now();
            // Retain terminal jobs in the map; sweep evicts them once the
            // retention window has elapsed so `session.list_jobs` can
            // surface recent history (RFC §10.5).
            $this->sweepTerminal();
        }
    }

    public function cancel(JobId $id, string $reason = 'user_aborted'): bool
    {
        $job = $this->tryGet($id);
        if (!$job instanceof Job || $job->state->isTerminal() || $job->cancelRequested) {
            return false;
        }
        // §7.3 defines no intermediate cancelling state: the job stays
        // `running` until the cooperating fiber unwinds to the terminal
        // `cancelled` state; the flag de-duplicates repeated requests.
        $job->cancelRequested = true;
        $job->cancellation->cancel(new CancelledException(new \RuntimeException($reason)));
        return true;
    }

    /** @return list<Job> */
    public function all(): array
    {
        return array_values($this->liveAndRetained());
    }

    public function count(): int
    {
        return \count($this->liveAndRetained());
    }

    /**
     * Jobs visible to read accessors: all non-terminal jobs plus terminal
     * jobs still within the retention window. Pure read — does not evict.
     *
     * @return array<string, Job>
     */
    private function liveAndRetained(): array
    {
        $now = $this->clock->now();
        $out = [];
        foreach ($this->jobs as $key => $job) {
            if ($job->state->isTerminal() && $job->terminatedAt instanceof \DateTimeImmutable) {
                $expires = $job->terminatedAt->modify('+' . $this->terminalRetentionSeconds . ' seconds');
                if ($expires <= $now) {
                    continue;
                }
            }
            $out[$key] = $job;
        }
        return $out;
    }

    /** Drop terminal jobs whose retention window has elapsed. */
    private function sweepTerminal(): void
    {
        $now = $this->clock->now();
        foreach ($this->jobs as $key => $job) {
            if (!$job->state->isTerminal() || !$job->terminatedAt instanceof \DateTimeImmutable) {
                continue;
            }
            $expires = $job->terminatedAt->modify('+' . $this->terminalRetentionSeconds . ' seconds');
            if ($expires <= $now) {
                unset($this->jobs[$key]);
            }
        }
    }
}
