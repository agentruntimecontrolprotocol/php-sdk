<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;

/** RFC §19 — resume a session/job after reconnection. v0.1: `after_message_id` only. */
final readonly class Resume extends MessageType
{
    public function __construct(
        public ?string $afterMessageId = null,
        public ?string $checkpointId = null,
        public bool $includeOpenStreams = true,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'resume';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['include_open_streams' => $this->includeOpenStreams];
        if ($this->afterMessageId !== null) {
            $out['after_message_id'] = $this->afterMessageId;
        }
        if ($this->checkpointId !== null) {
            $out['checkpoint_id'] = $this->checkpointId;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $after = null;
        if (isset($data['after_message_id']) && \is_string($data['after_message_id'])) {
            $after = $data['after_message_id'];
        }
        $checkpoint = null;
        if (isset($data['checkpoint_id']) && \is_string($data['checkpoint_id'])) {
            $checkpoint = $data['checkpoint_id'];
        }
        $include = isset($data['include_open_streams']) ? $data['include_open_streams'] === true : true;
        return new self($after, $checkpoint, $include);
    }
}
