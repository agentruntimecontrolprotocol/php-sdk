<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §8.4 — runtime requests fresh credentials. */
final readonly class SessionRefresh extends MessageType
{
    public function __construct(
        public \DateTimeImmutable $deadline,
        public ?string $reason = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.refresh';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['deadline' => $this->deadline->format(\DateTimeInterface::RFC3339_EXTENDED)];
        if ($this->reason !== null) {
            $out['reason'] = $this->reason;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $deadline = $data['deadline'] ?? throw new InvalidArgumentException('deadline missing');
        if (!\is_string($deadline)) {
            throw new InvalidArgumentException('deadline must be string');
        }
        $reason = null;
        if (isset($data['reason'])) {
            if (!\is_string($data['reason'])) {
                throw new InvalidArgumentException('reason must be string');
            }
            $reason = $data['reason'];
        }
        return new self(new \DateTimeImmutable($deadline), $reason);
    }
}
