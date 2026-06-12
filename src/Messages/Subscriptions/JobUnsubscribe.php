<?php

declare(strict_types=1);

namespace Arcp\Messages\Subscriptions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\JobId;

/** ARCP v1.1 §7.6 — detach from a job's event stream: `{job_id}`. */
final readonly class JobUnsubscribe extends MessageType
{
    public function __construct(public JobId $jobId)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.unsubscribe';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['job_id' => (string) $this->jobId];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $jobId = $data['job_id']
            ?? throw new InvalidRequestException('job.unsubscribe job_id missing');
        return new self(JobId::fromJson($jobId));
    }
}
