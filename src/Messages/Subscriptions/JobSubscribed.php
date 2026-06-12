<?php

declare(strict_types=1);

namespace Arcp\Messages\Subscriptions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\JobId;
use Arcp\Ids\TraceId;

/**
 * ARCP v1.1 §7.6 — subscription acknowledgement: `{job_id,
 * current_status, agent, lease, parent_job_id, trace_id,
 * subscribed_from, replayed}`.
 */
final readonly class JobSubscribed extends MessageType
{
    /**
     * @param array<string, mixed>|null $lease Effective lease of the
     *                                         subscribed job, when visible.
     *
     * @size-check-suppress Wire-shape DTO mapping to §7.6.
     */
    public function __construct(
        public JobId $jobId,
        public string $currentStatus,
        public string $agent,
        public ?array $lease = null,
        public ?JobId $parentJobId = null,
        public ?TraceId $traceId = null,
        public int $subscribedFrom = 0,
        public bool $replayed = false,
    ) {
        if ($currentStatus === '') {
            throw new InvalidRequestException('job.subscribed current_status must be non-empty');
        }
        if ($agent === '') {
            throw new InvalidRequestException('job.subscribed agent must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.subscribed';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'job_id' => (string) $this->jobId,
            'current_status' => $this->currentStatus,
            'agent' => $this->agent,
            'subscribed_from' => $this->subscribedFrom,
            'replayed' => $this->replayed,
        ];
        if ($this->lease !== null) {
            $out['lease'] = $this->lease;
        }
        if ($this->parentJobId instanceof JobId) {
            $out['parent_job_id'] = (string) $this->parentJobId;
        }
        if ($this->traceId instanceof TraceId) {
            $out['trace_id'] = (string) $this->traceId;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $jobId = $data['job_id']
            ?? throw new InvalidRequestException('job.subscribed job_id missing');
        $status = $data['current_status']
            ?? throw new InvalidRequestException('job.subscribed current_status missing');
        $agent = $data['agent']
            ?? throw new InvalidRequestException('job.subscribed agent missing');
        if (!\is_string($status) || !\is_string($agent)) {
            throw new InvalidRequestException('job.subscribed current_status/agent must be strings');
        }
        $lease = null;
        if (isset($data['lease'])) {
            if (!\is_array($data['lease'])) {
                throw new InvalidRequestException('job.subscribed lease must be object');
            }
            /** @var array<string, mixed> $lease */
            $lease = $data['lease'];
        }
        $subscribedFrom = 0;
        if (isset($data['subscribed_from'])) {
            if (!\is_int($data['subscribed_from'])) {
                throw new InvalidRequestException('job.subscribed subscribed_from must be integer');
            }
            $subscribedFrom = $data['subscribed_from'];
        }
        return new self(
            JobId::fromJson($jobId),
            $status,
            $agent,
            $lease,
            isset($data['parent_job_id']) ? JobId::fromJson($data['parent_job_id']) : null,
            isset($data['trace_id']) ? TraceId::fromJson($data['trace_id']) : null,
            $subscribedFrom,
            isset($data['replayed']) && $data['replayed'] === true,
        );
    }
}
