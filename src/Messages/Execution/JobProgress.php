<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** RFC §10.1 — progress update on a running job. */
final readonly class JobProgress extends MessageType
{
    public function __construct(
        public ?int $percent = null,
        public ?string $message = null,
    ) {
        if ($percent !== null && ($percent < 0 || $percent > 100)) {
            throw new InvalidRequestException('percent must be 0..100');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.progress';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [];
        if ($this->percent !== null) {
            $out['percent'] = $this->percent;
        }
        if ($this->message !== null) {
            $out['message'] = $this->message;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $percent = null;
        if (isset($data['percent']) && \is_int($data['percent'])) {
            $percent = $data['percent'];
        }
        $message = null;
        if (isset($data['message']) && \is_string($data['message'])) {
            $message = $data['message'];
        }
        return new self($percent, $message);
    }
}
