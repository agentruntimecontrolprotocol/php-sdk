<?php

declare(strict_types=1);

namespace Arcp\Messages\Permissions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** RFC §15.4 — client refused. */
final readonly class PermissionDeny extends MessageType
{
    public function __construct(
        public string $permission,
        public string $resource,
        public string $operation,
        public string $reason = '',
    ) {
        if ($permission === '' || $resource === '' || $operation === '') {
            throw new InvalidRequestException('permission/resource/operation must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'permission.deny';
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
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $p = $data['permission'] ?? throw new InvalidRequestException('permission missing');
        $r = $data['resource'] ?? throw new InvalidRequestException('resource missing');
        $o = $data['operation'] ?? throw new InvalidRequestException('operation missing');
        if (!\is_string($p) || !\is_string($r) || !\is_string($o)) {
            throw new InvalidRequestException('permission/resource/operation must be strings');
        }
        $reason = '';
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        return new self($p, $r, $o, $reason);
    }
}
