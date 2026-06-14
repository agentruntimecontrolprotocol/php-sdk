<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Errors\InvalidRequestException;
use Arcp\Messages\Session\Jobs;
use Arcp\Messages\Session\ListJobs;
use Arcp\Runtime\AgentRef;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Job;
use Arcp\Runtime\Session;

/** @internal Handles ARCP v1.1 `session.list_jobs`. */
final readonly class JobListHandler
{
    public function __construct(private ARCPRuntime $runtime)
    {
    }

    public function handle(Session $session, Envelope $env, ListJobs $msg): void
    {
        $jobs = array_values(array_filter(
            $this->runtime->jobs->all(),
            fn (Job $job): bool => $this->visible($session, $job) && $this->matches($job, $msg),
        ));
        usort($jobs, $this->compare(...));
        [$page, $next] = $this->paginate($jobs, $msg->cursor, min($msg->limit, 100));
        $this->runtime->emit($session, new Jobs(
            requestId: (string) $env->id,
            jobs: array_map(fn (Job $job): array => $this->entry($job), $page),
            nextCursor: $next,
        ), ['correlation_id' => $env->id]);
    }

    private function visible(Session $session, Job $job): bool
    {
        if ($job->session === $session) {
            return true;
        }
        return $session->principal !== null && $session->principal === $job->session->principal;
    }

    private function matches(Job $job, ListJobs $msg): bool
    {
        $filter = $msg->filter;
        if (!$this->matchesStatus($job, $filter)) {
            return false;
        }
        if (!$this->matchesAgent($job, $filter)) {
            return false;
        }
        return $this->matchesCreatedAt($job, $filter);
    }

    /** @param array<string, mixed> $filter */
    private function matchesStatus(Job $job, array $filter): bool
    {
        if (!isset($filter['status'])) {
            return true;
        }
        $statuses = \is_string($filter['status']) ? [$filter['status']] : $filter['status'];
        if (!\is_array($statuses)) {
            throw new InvalidRequestException('filter.status must be string or list');
        }
        return \in_array($job->state->value, $statuses, true);
    }

    /** @param array<string, mixed> $filter */
    private function matchesAgent(Job $job, array $filter): bool
    {
        if (!isset($filter['agent'])) {
            return true;
        }
        if (!\is_string($filter['agent'])) {
            throw new InvalidRequestException('filter.agent must be string');
        }
        $ref = AgentRef::parse($filter['agent']);
        return $job->tool === $ref->name
            && ($ref->version === null || $job->toolVersion === $ref->version);
    }

    /** @param array<string, mixed> $filter */
    private function matchesCreatedAt(Job $job, array $filter): bool
    {
        foreach (['created_after' => '>', 'created_before' => '<'] as $key => $op) {
            if (!isset($filter[$key])) {
                continue;
            }
            if (!\is_string($filter[$key])) {
                throw new InvalidRequestException("filter.$key must be string");
            }
            $threshold = new \DateTimeImmutable($filter[$key]);
            if ($op === '>' && $job->createdAt <= $threshold) {
                return false;
            }
            if ($op === '<' && $job->createdAt >= $threshold) {
                return false;
            }
        }
        return true;
    }

    private function compare(Job $a, Job $b): int
    {
        $timeCmp = $a->createdAt <=> $b->createdAt;
        return $timeCmp !== 0 ? $timeCmp : strcmp((string) $a->id, (string) $b->id);
    }

    /**
     * The list is sorted by the `(created_at, id)` tuple, so the cursor
     * encodes that tuple and the start offset is found with a binary
     * search — O(log n) per page rather than the previous O(n) scan.
     *
     * @param list<Job> $jobs
     *
     * @return array{0: list<Job>, 1: ?string}
     */
    private function paginate(array $jobs, ?string $cursor, int $limit): array
    {
        $start = $cursor !== null && $cursor !== ''
            ? $this->afterCursor($jobs, $cursor)
            : 0;
        $page = \array_slice($jobs, $start, $limit);
        $last = $page !== [] ? $page[\count($page) - 1] : null;
        $next = $start + $limit < \count($jobs) && $last instanceof Job
            ? $this->encodeCursor($last)
            : null;
        return [$page, $next];
    }

    /**
     * Binary-search the upper bound: the index of the first job whose
     * `(created_at, id)` tuple is strictly greater than the cursor.
     *
     * @param list<Job> $jobs
     */
    private function afterCursor(array $jobs, string $cursor): int
    {
        $key = $this->decodeCursor($cursor);
        if ($key === null) {
            return 0;
        }
        $lo = 0;
        $hi = \count($jobs);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $job = $jobs[$mid] ?? null;
            if (!$job instanceof Job) {
                break;
            }
            if ($this->compareKey($job, $key) <= 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    private function encodeCursor(Job $job): string
    {
        // Microsecond precision + explicit offset so the cursor round-trips
        // to the exact instant; RFC3339_EXTENDED would truncate to
        // milliseconds and misplace the binary-search boundary.
        return base64_encode(
            $job->createdAt->format('Y-m-d\TH:i:s.uP') . "\x1f" . (string) $job->id,
        );
    }

    /** @return array{ts: \DateTimeImmutable, id: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        $raw = base64_decode($cursor, true);
        if ($raw === false) {
            return null;
        }
        $parts = explode("\x1f", $raw, 2);
        if (\count($parts) !== 2) {
            return null;
        }
        try {
            $ts = new \DateTimeImmutable($parts[0]);
        } catch (\Exception) {
            return null;
        }
        return ['ts' => $ts, 'id' => $parts[1]];
    }

    /** @param array{ts: \DateTimeImmutable, id: string} $key */
    private function compareKey(Job $job, array $key): int
    {
        $timeCmp = $job->createdAt <=> $key['ts'];
        return $timeCmp !== 0 ? $timeCmp : strcmp((string) $job->id, $key['id']);
    }

    /** @return array<string, mixed> */
    private function entry(Job $job): array
    {
        // §6.6 entry shape: {job_id, agent, status, lease, parent_job_id,
        // created_at, trace_id, last_event_seq}.
        // §14: never surface plaintext credential `value` on an
        // introspection surface. Credentials are delivered only on
        // job.accepted to the submitter; the list_jobs inventory omits them
        // entirely (clients re-fetch via the job's own accepted payload).
        return [
            'job_id' => (string) $job->id,
            'agent' => $job->toolRef(),
            'status' => $job->state->value,
            'lease' => $job->lease?->toArray(),
            'parent_job_id' => $job->parentJobId?->__toString(),
            'created_at' => $job->createdAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'trace_id' => $job->invocation->traceId?->__toString(),
            'last_event_seq' => $job->lastEventSeq,
        ];
    }
}
