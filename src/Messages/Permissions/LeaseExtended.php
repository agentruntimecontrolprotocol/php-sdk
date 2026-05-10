<?php

declare(strict_types=1);

namespace Arcp\Messages\Permissions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\LeaseId;

/** RFC §15.5 — lease horizon extended. */
final readonly class LeaseExtended extends MessageType
{
    public function __construct(
        public LeaseId $leaseId,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'lease.extended';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'lease_id' => (string) $this->leaseId,
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['lease_id'] ?? throw new InvalidArgumentException('lease_id missing');
        $exp = $data['expires_at'] ?? throw new InvalidArgumentException('expires_at missing');
        if (!\is_string($exp)) {
            throw new InvalidArgumentException('expires_at must be string');
        }
        return new static(LeaseId::fromJson($id), new \DateTimeImmutable($exp));
    }
}
