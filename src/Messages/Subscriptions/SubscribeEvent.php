<?php

declare(strict_types=1);

namespace Arcp\Messages\Subscriptions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** RFC §13.1 — wraps a delivered event under `payload.event`. */
final readonly class SubscribeEvent extends MessageType
{
    /** @param array<string, mixed> $event Wire-shape of the wrapped envelope. */
    public function __construct(public array $event)
    {
        if ($event === []) {
            throw new InvalidRequestException('event must be a non-empty object');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'subscribe.event';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['event' => $this->event];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $ev = $data['event'] ?? throw new InvalidRequestException('event missing');
        if (!\is_array($ev)) {
            throw new InvalidRequestException('event must be object');
        }
        /** @var array<string, mixed> $ev */
        return new self($ev);
    }
}
