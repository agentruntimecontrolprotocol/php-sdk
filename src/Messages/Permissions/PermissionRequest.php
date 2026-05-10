<?php

declare(strict_types=1);

namespace Arcp\Messages\Permissions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §15.4 — permission challenge from runtime. */
final readonly class PermissionRequest extends MessageType
{
    public function __construct(
        public string $permission,
        public string $resource,
        public string $operation,
        public string $reason = '',
        public ?int $requestedLeaseSeconds = null,
    ) {
        if ($permission === '' || $resource === '' || $operation === '') {
            throw new InvalidArgumentException('permission/resource/operation must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'permission.request';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'permission' => $this->permission,
            'resource' => $this->resource,
            'operation' => $this->operation,
        ];
        if ($this->reason !== '') {
            $out['reason'] = $this->reason;
        }
        if ($this->requestedLeaseSeconds !== null) {
            $out['requested_lease_seconds'] = $this->requestedLeaseSeconds;
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
        $reason = '';
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        $lease = null;
        if (isset($data['requested_lease_seconds']) && \is_int($data['requested_lease_seconds'])) {
            $lease = $data['requested_lease_seconds'];
        }
        return new static($p, $r, $o, $reason, $lease);
    }
}
