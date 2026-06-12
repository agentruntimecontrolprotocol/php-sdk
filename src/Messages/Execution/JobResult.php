<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/**
 * ARCP v1.1 §7.3 / §8.4 — terminal job result. When `result_id` is
 * present the result was streamed as `result_chunk` events and is the
 * concatenation of the chunks; otherwise `result` carries it inline.
 */
final readonly class JobResult extends MessageType
{
    public const string SUCCESS = 'success';

    public function __construct(
        public string $finalStatus = self::SUCCESS,
        public mixed $result = null,
        public ?string $resultId = null,
        public ?int $resultSize = null,
        public ?string $summary = null,
    ) {
        if ($finalStatus === '') {
            throw new InvalidRequestException('job.result final_status missing');
        }
        if ($resultId !== null && $result !== null) {
            // §8.4: inline result and streamed chunks are mutually exclusive.
            throw new InvalidRequestException(
                'job.result must not carry both result and result_id',
            );
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.result';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['final_status' => $this->finalStatus];
        if ($this->result !== null) {
            $out['result'] = $this->result;
        }
        if ($this->resultId !== null) {
            $out['result_id'] = $this->resultId;
        }
        if ($this->resultSize !== null) {
            $out['result_size'] = $this->resultSize;
        }
        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $finalStatus = $data['final_status']
            ?? throw new InvalidRequestException('job.result final_status missing');
        if (!\is_string($finalStatus)) {
            throw new InvalidRequestException('job.result final_status must be string');
        }
        $resultId = null;
        if (isset($data['result_id'])) {
            if (!\is_string($data['result_id'])) {
                throw new InvalidRequestException('job.result result_id must be string');
            }
            $resultId = $data['result_id'];
        }
        $resultSize = null;
        if (isset($data['result_size'])) {
            if (!\is_int($data['result_size'])) {
                throw new InvalidRequestException('job.result result_size must be integer');
            }
            $resultSize = $data['result_size'];
        }
        $summary = null;
        if (isset($data['summary']) && \is_string($data['summary'])) {
            $summary = $data['summary'];
        }
        return new self($finalStatus, $data['result'] ?? null, $resultId, $resultSize, $summary);
    }
}
