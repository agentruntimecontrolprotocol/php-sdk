<?php

declare(strict_types=1);

namespace Arcp\Messages\Telemetry;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/**
 * `event.emit` — generic typed event envelope (RFC §6.2).
 *
 * Used for ad-hoc structured events (subscription markers like
 * `subscription.backfill_complete`, custom domain events). The runtime
 * does not interpret `payload.type` beyond delivering it through
 * subscriptions.
 *
 * @phpstan-type EventBody array<string, mixed>
 */
final readonly class EventEmit extends MessageType
{
    /** @param EventBody $attributes */
    public function __construct(
        public string $eventType,
        public array $attributes = [],
    ) {
        if ($eventType === '') {
            throw new InvalidArgumentException('event.emit type must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'event.emit';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $eventType = $data['type'] ?? throw new InvalidArgumentException('event.emit payload.type missing');
        if (!\is_string($eventType)) {
            throw new InvalidArgumentException('event.emit payload.type must be string');
        }
        $attributes = [];
        if (isset($data['attributes'])) {
            if (!\is_array($data['attributes'])) {
                throw new InvalidArgumentException('event.emit payload.attributes must be object');
            }
            /** @var EventBody $attributes */
            $attributes = $data['attributes'];
        }
        return new self($eventType, $attributes);
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['type' => $this->eventType];
        if ($this->attributes !== []) {
            $out['attributes'] = $this->attributes;
        }
        return $out;
    }
}
