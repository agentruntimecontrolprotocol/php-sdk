<?php

declare(strict_types=1);

namespace Arcp\Messages\Subscriptions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\SubscriptionId;

/** RFC §13.1 — subscription accepted; envelope `subscription_id` mirrors the payload. */
final readonly class SubscribeAccepted extends MessageType
{
    public function __construct(public SubscriptionId $subscriptionId)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'subscribe.accepted';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['subscription_id' => (string) $this->subscriptionId];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['subscription_id']
            ?? throw new InvalidRequestException('subscription_id missing');
        return new self(SubscriptionId::fromJson($id));
    }
}
