<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §10.4 — request to cancel a job/stream/session. */
final readonly class Cancel extends MessageType
{
    public function __construct(
        public string $target,
        public string $targetId,
        public string $reason = '',
        public ?int $deadlineMs = null,
    ) {
        if (!\in_array($target, ['job', 'stream', 'session'], true)) {
            throw new InvalidArgumentException('cancel.target must be job|stream|session');
        }
        if ($targetId === '') {
            throw new InvalidArgumentException('cancel.target_id must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'cancel';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['target' => $this->target, 'target_id' => $this->targetId];
        if ($this->reason !== '') {
            $out['reason'] = $this->reason;
        }
        if ($this->deadlineMs !== null) {
            $out['deadline_ms'] = $this->deadlineMs;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $target = $data['target'] ?? throw new InvalidArgumentException('target missing');
        $targetId = $data['target_id'] ?? throw new InvalidArgumentException('target_id missing');
        if (!\is_string($target) || !\is_string($targetId)) {
            throw new InvalidArgumentException('target/target_id must be strings');
        }
        $reason = '';
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        $deadline = null;
        if (isset($data['deadline_ms']) && \is_int($data['deadline_ms'])) {
            $deadline = $data['deadline_ms'];
        }
        return new static($target, $targetId, $reason, $deadline);
    }
}
