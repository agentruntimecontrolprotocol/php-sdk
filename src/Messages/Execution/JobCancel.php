<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\JobId;

/**
 * ARCP v1.1 §7.4 — request to cancel a non-terminal job. The runtime
 * acknowledges with `job.cancelled` and the job terminates with
 * `job.error` (code `CANCELLED`, `final_status: "cancelled"`).
 */
final readonly class JobCancel extends MessageType
{
    public function __construct(
        public JobId $jobId,
        public string $reason = '',
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.cancel';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['job_id' => (string) $this->jobId];
        if ($this->reason !== '') {
            $out['reason'] = $this->reason;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $jobId = $data['job_id'] ?? throw new InvalidRequestException('job.cancel job_id missing');
        $reason = '';
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        return new self(JobId::fromJson($jobId), $reason);
    }
}
