<?php

declare(strict_types=1);

namespace Arcp\Messages\Permissions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\LeaseId;

/** RFC §15.5 — lease materialized. */
final readonly class LeaseGranted extends MessageType
{
    public function __construct(
        public LeaseId $leaseId,
        public string $permission,
        public string $resource,
        public string $operation,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'lease.granted';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'lease_id' => (string) $this->leaseId,
            'permission' => $this->permission,
            'resource' => $this->resource,
            'operation' => $this->operation,
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['lease_id'] ?? throw new InvalidArgumentException('lease_id missing');
        $p = $data['permission'] ?? throw new InvalidArgumentException('permission missing');
        $r = $data['resource'] ?? throw new InvalidArgumentException('resource missing');
        $o = $data['operation'] ?? throw new InvalidArgumentException('operation missing');
        $exp = $data['expires_at'] ?? throw new InvalidArgumentException('expires_at missing');
        if (!\is_string($p) || !\is_string($r) || !\is_string($o) || !\is_string($exp)) {
            throw new InvalidArgumentException('field types wrong');
        }
        return new static(LeaseId::fromJson($id), $p, $r, $o, new \DateTimeImmutable($exp));
    }
}
