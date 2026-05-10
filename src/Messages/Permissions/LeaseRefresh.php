<?php

declare(strict_types=1);

namespace Arcp\Messages\Permissions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\LeaseId;

/** RFC §15.5 — holder requests an extension. */
final readonly class LeaseRefresh extends MessageType
{
    public function __construct(
        public LeaseId $leaseId,
        public ?int $extendSeconds = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'lease.refresh';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['lease_id' => (string) $this->leaseId];
        if ($this->extendSeconds !== null) {
            $out['extend_seconds'] = $this->extendSeconds;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['lease_id'] ?? throw new InvalidArgumentException('lease_id missing');
        $ext = null;
        if (isset($data['extend_seconds']) && \is_int($data['extend_seconds'])) {
            $ext = $data['extend_seconds'];
        }
        return new static(LeaseId::fromJson($id), $ext);
    }
}
