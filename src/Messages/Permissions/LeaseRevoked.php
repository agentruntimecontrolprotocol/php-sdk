<?php

declare(strict_types=1);

namespace Arcp\Messages\Permissions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\LeaseId;

/** RFC §15.5 — lease cancelled before natural expiry. */
final readonly class LeaseRevoked extends MessageType
{
    public function __construct(
        public LeaseId $leaseId,
        public string $reason = '',
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'lease.revoked';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['lease_id' => (string) $this->leaseId];
        if ($this->reason !== '') {
            $out['reason'] = $this->reason;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['lease_id'] ?? throw new InvalidRequestException('lease_id missing');
        $reason = '';
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        return new self(LeaseId::fromJson($id), $reason);
    }
}
