<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Arcp\Envelope\Envelope;
use Arcp\Errors\NotFoundException;
use Arcp\Ids\JobId;

/**
 * Tracks in-flight jobs and drives the state machine (RFC §10.2). Each
 * job runs in its own fiber via {@see async()}; cancellation is
 * cooperative through the per-job {@see DeferredCancellation}.
 */
final class JobManager
{
    /** @var array<string, Job> */
    private array $jobs = [];

    public function start(Session $session, Envelope $invocation, string $tool): Job
    {
        $job = new Job(
            id: JobId::random(),
            session: $session,
            invocation: $invocation,
            cancellation: new DeferredCancellation(),
            tool: $tool,
        );
        $this->jobs[(string) $job->id] = $job;
        return $job;
    }

    public function get(JobId $id): Job
    {
        return $this->jobs[(string) $id]
            ?? throw new NotFoundException(\sprintf('job %s not found', $id));
    }

    public function tryGet(JobId $id): ?Job
    {
        return $this->jobs[(string) $id] ?? null;
    }

    public function transition(Job $job, JobState $next): void
    {
        $job->state = $next;
        if ($next->isTerminal()) {
            unset($this->jobs[(string) $job->id]);
        }
    }

    public function cancel(JobId $id, string $reason = 'user_aborted'): bool
    {
        $job = $this->tryGet($id);
        if (!$job instanceof Job || $job->state->isTerminal()) {
            return false;
        }
        $job->cancellation->cancel(new CancelledException(new \RuntimeException($reason)));
        return true;
    }

    /** @return list<Job> */
    public function all(): array
    {
        return array_values($this->jobs);
    }

    public function count(): int
    {
        return \count($this->jobs);
    }
}
