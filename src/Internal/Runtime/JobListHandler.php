<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Errors\InvalidArgumentException;
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
            jobs: array_map(fn (Job $job): array => $this->entry($session, $job), $page),
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
            throw new InvalidArgumentException('filter.status must be string or list');
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
            throw new InvalidArgumentException('filter.agent must be string');
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
                throw new InvalidArgumentException("filter.$key must be string");
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
     * @param list<Job> $jobs
     *
     * @return array{0: list<Job>, 1: ?string}
     */
    private function paginate(array $jobs, ?string $cursor, int $limit): array
    {
        $start = 0;
        if ($cursor !== null && $cursor !== '') {
            foreach ($jobs as $i => $job) {
                if ((string) $job->id === $cursor) {
                    $start = $i + 1;
                    break;
                }
            }
        }
        $page = \array_slice($jobs, $start, $limit);
        $last = $page !== [] ? $page[\count($page) - 1] : null;
        $next = $start + $limit < \count($jobs) && $last instanceof Job ? (string) $last->id : null;
        return [$page, $next];
    }

    /** @return array<string, mixed> */
    private function entry(Session $session, Job $job): array
    {
        $entry = [
            'job_id' => (string) $job->id,
            'agent' => $job->toolRef(),
            'status' => $job->state->value,
            'created_at' => $job->createdAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'trace_id' => $job->invocation->traceId?->__toString(),
        ];
        if ($this->sameSubmitter($session, $job)) {
            $credentials = $this->runtime->credentials->forJob($job->id);
            if ($credentials !== []) {
                $entry['credentials'] = array_map(
                    fn ($credential): array => $credential->toArray(),
                    $credentials,
                );
            }
        }
        return $entry;
    }

    private function sameSubmitter(Session $session, Job $job): bool
    {
        return $session === $job->session
            || ($session->principal !== null && $session->principal === $job->session->principal);
    }
}
