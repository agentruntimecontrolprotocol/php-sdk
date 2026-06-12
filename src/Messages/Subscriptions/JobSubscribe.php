<?php

declare(strict_types=1);

namespace Arcp\Messages\Subscriptions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\JobId;

/**
 * ARCP v1.1 §7.6 — attach to a job's event stream:
 * `{job_id, from_event_seq?, history?}`.
 *
 * `from_event_seq` is honored only with `history: true`; the runtime
 * replays buffered events with `seq > from_event_seq` before live tail.
 */
final readonly class JobSubscribe extends MessageType
{
    public function __construct(
        public JobId $jobId,
        public ?int $fromEventSeq = null,
        public bool $history = false,
    ) {
        if ($fromEventSeq !== null && $fromEventSeq < 0) {
            throw new InvalidRequestException('job.subscribe from_event_seq must be non-negative');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.subscribe';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['job_id' => (string) $this->jobId];
        if ($this->fromEventSeq !== null) {
            $out['from_event_seq'] = $this->fromEventSeq;
        }
        if ($this->history) {
            $out['history'] = true;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $jobId = $data['job_id']
            ?? throw new InvalidRequestException('job.subscribe job_id missing');
        $fromEventSeq = null;
        if (isset($data['from_event_seq'])) {
            if (!\is_int($data['from_event_seq'])) {
                throw new InvalidRequestException('job.subscribe from_event_seq must be integer');
            }
            $fromEventSeq = $data['from_event_seq'];
        }
        $history = isset($data['history']) && $data['history'] === true;
        return new self(JobId::fromJson($jobId), $fromEventSeq, $history);
    }
}
