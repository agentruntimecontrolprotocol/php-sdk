<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;

/** RFC §10.2 / §10.4 — terminal: job cancelled. */
final readonly class JobCancelled extends MessageType
{
    public function __construct(
        public string $reason = '',
        public ?string $code = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.cancelled';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [];
        if ($this->reason !== '') {
            $out['reason'] = $this->reason;
        }
        if ($this->code !== null) {
            $out['code'] = $this->code;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $reason = '';
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        $code = null;
        if (isset($data['code']) && \is_string($data['code'])) {
            $code = $data['code'];
        }
        return new self($reason, $code);
    }
}
