<?php

declare(strict_types=1);

namespace Arcp\Messages\Subscriptions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §13.1 — open a subscription. */
final readonly class Subscribe extends MessageType
{
    /**
     * @param array<string, mixed> $filter Per RFC §13.2 (session_id, trace_id, job_id, stream_id, types, min_priority).
     */
    public function __construct(
        public array $filter,
        public ?string $sinceMessageId = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'subscribe';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['filter' => $this->filter];
        if ($this->sinceMessageId !== null) {
            $out['since'] = ['after_message_id' => $this->sinceMessageId];
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $filter = $data['filter'] ?? [];
        if (!\is_array($filter)) {
            throw new InvalidArgumentException('filter must be object');
        }
        $since = null;
        if (isset($data['since']) && \is_array($data['since']) && isset($data['since']['after_message_id'])) {
            $sm = $data['since']['after_message_id'];
            if (!\is_string($sm)) {
                throw new InvalidArgumentException('since.after_message_id must be string');
            }
            $since = $sm;
        }
        /** @var array<string, mixed> $filter */
        return new static($filter, $since);
    }
}
