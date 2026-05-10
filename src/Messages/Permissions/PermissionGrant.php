<?php

declare(strict_types=1);

namespace Arcp\Messages\Permissions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §15.4 — client granted the requested permission. */
final readonly class PermissionGrant extends MessageType
{
    public function __construct(
        public string $permission,
        public string $resource,
        public string $operation,
        public ?int $leaseSeconds = null,
    ) {
        if ($permission === '' || $resource === '' || $operation === '') {
            throw new InvalidArgumentException('permission/resource/operation must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'permission.grant';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'permission' => $this->permission,
            'resource' => $this->resource,
            'operation' => $this->operation,
        ];
        if ($this->leaseSeconds !== null) {
            $out['lease_seconds'] = $this->leaseSeconds;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $p = $data['permission'] ?? throw new InvalidArgumentException('permission missing');
        $r = $data['resource'] ?? throw new InvalidArgumentException('resource missing');
        $o = $data['operation'] ?? throw new InvalidArgumentException('operation missing');
        if (!\is_string($p) || !\is_string($r) || !\is_string($o)) {
            throw new InvalidArgumentException('permission/resource/operation must be strings');
        }
        $lease = null;
        if (isset($data['lease_seconds']) && \is_int($data['lease_seconds'])) {
            $lease = $data['lease_seconds'];
        }
        return new static($p, $r, $o, $lease);
    }
}
