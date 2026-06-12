<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\InvalidRequestException;

/**
 * ARCP v1.1 §7.3 / §12 — terminal job error: `{final_status, code,
 * message, retryable, details?}`. `final_status` is one of the error
 * terminals (`error`, `cancelled`, `timed_out`).
 */
final readonly class JobError extends MessageType
{
    public const string ERROR = 'error';
    public const string CANCELLED = 'cancelled';
    public const string TIMED_OUT = 'timed_out';

    public function __construct(
        public string $finalStatus,
        public ErrorPayload $error,
    ) {
        if (!\in_array($finalStatus, [self::ERROR, self::CANCELLED, self::TIMED_OUT], true)) {
            throw new InvalidRequestException(
                'job.error final_status must be error|cancelled|timed_out',
                ['final_status' => $finalStatus],
            );
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.error';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['final_status' => $this->finalStatus, ...$this->error->toArray()];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $finalStatus = $data['final_status']
            ?? throw new InvalidRequestException('job.error final_status missing');
        if (!\is_string($finalStatus)) {
            throw new InvalidRequestException('job.error final_status must be string');
        }
        unset($data['final_status']);
        return new self($finalStatus, ErrorPayload::fromArray($data));
    }
}
