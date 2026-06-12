<?php

declare(strict_types=1);

namespace Arcp\Messages\Subscriptions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §13.4 — runtime terminated the subscription. */
final readonly class SubscribeClosed extends MessageType
{
    public function __construct(
        public string $code,
        public string $reason = '',
    ) {
        if ($code === '') {
            throw new InvalidArgumentException('subscribe.closed code must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'subscribe.closed';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['code' => $this->code];
        if ($this->reason !== '') {
            $out['reason'] = $this->reason;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        if (!isset($data['code']) || !\is_string($data['code']) || $data['code'] === '') {
            throw new InvalidArgumentException('subscribe.closed code missing or empty');
        }
        $code = $data['code'];
        $reason = '';
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        return new self($code, $reason);
    }
}
