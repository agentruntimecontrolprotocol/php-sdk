<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** RFC §10.4 — runtime refused the cancellation. */
final readonly class CancelRefused extends MessageType
{
    public function __construct(public string $reason)
    {
        if ($reason === '') {
            throw new InvalidRequestException('reason must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'cancel.refused';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['reason' => $this->reason];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $reason = $data['reason'] ?? throw new InvalidRequestException('reason missing');
        if (!\is_string($reason)) {
            throw new InvalidRequestException('reason must be string');
        }
        return new self($reason);
    }
}
