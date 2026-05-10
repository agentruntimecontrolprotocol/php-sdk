<?php

declare(strict_types=1);

namespace Arcp\Messages\Subscriptions;

use Arcp\Envelope\MessageType;

/** RFC §13.4 — close a subscription. */
final readonly class Unsubscribe extends MessageType
{
    public function __construct()
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'unsubscribe';
    }

    #[\Override]
    public function toArray(): array
    {
        return [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
