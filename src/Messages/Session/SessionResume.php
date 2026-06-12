<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;

/**
 * ARCP v1.1 §6.3 — resume a session after a transport drop. Phase 1
 * renames the wire type; the §6.3 payload (`last_event_seq` +
 * `resume_token`) lands with the resume-token work.
 */
final readonly class SessionResume extends MessageType
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
        return 'session.resume';
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
        $include = isset($data['include_open_streams'])
            ? $data['include_open_streams'] === true
            : true;
        return new self($after, $checkpoint, $include);
    }
}
