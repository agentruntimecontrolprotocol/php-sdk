<?php

declare(strict_types=1);

namespace Arcp\Messages\Permissions;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\LeaseId;
use Arcp\Runtime\CostBudget;
use Arcp\Runtime\ModelUse;

/** RFC §15.5 — lease materialized. */
final readonly class LeaseGranted extends MessageType
{
    public function __construct(
        public LeaseId $leaseId,
        public string $permission,
        public string $resource,
        public string $operation,
        public \DateTimeImmutable $expiresAt,
        public ?ModelUse $modelUse = null,
        public ?CostBudget $costBudget = null,
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
        $out = [
            'lease_id' => (string) $this->leaseId,
            'permission' => $this->permission,
            'resource' => $this->resource,
            'operation' => $this->operation,
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
        if ($this->modelUse !== null) {
            $out['model.use'] = $this->modelUse->patterns();
        }
        if ($this->costBudget !== null) {
            $out['cost.budget'] = $this->costBudget->patterns();
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['lease_id'] ?? throw new InvalidRequestException('lease_id missing');
        $p = $data['permission'] ?? throw new InvalidRequestException('permission missing');
        $r = $data['resource'] ?? throw new InvalidRequestException('resource missing');
        $o = $data['operation'] ?? throw new InvalidRequestException('operation missing');
        $exp = $data['expires_at'] ?? throw new InvalidRequestException('expires_at missing');
        if (!\is_string($p) || !\is_string($r) || !\is_string($o) || !\is_string($exp)) {
            throw new InvalidRequestException('field types wrong');
        }
        return new self(
            LeaseId::fromJson($id),
            $p,
            $r,
            $o,
            new \DateTimeImmutable($exp),
            self::modelUseFromArray($data),
            self::costBudgetFromArray($data),
        );
    }

    /** @param array<string, mixed> $data */
    private static function modelUseFromArray(array $data): ?ModelUse
    {
        if (!isset($data['model.use'])) {
            return null;
        }
        if (!\is_array($data['model.use'])) {
            throw new InvalidRequestException('model.use must be list');
        }
        $patterns = [];
        foreach ($data['model.use'] as $pattern) {
            if (!\is_string($pattern)) {
                throw new InvalidRequestException('model.use entries must be strings');
            }
            $patterns[] = $pattern;
        }
        return ModelUse::fromPatterns($patterns);
    }

    /** @param array<string, mixed> $data */
    private static function costBudgetFromArray(array $data): ?CostBudget
    {
        if (!isset($data['cost.budget'])) {
            return null;
        }
        if (!\is_array($data['cost.budget'])) {
            throw new InvalidRequestException('cost.budget must be list');
        }
        $patterns = [];
        foreach ($data['cost.budget'] as $pattern) {
            if (!\is_string($pattern)) {
                throw new InvalidRequestException('cost.budget entries must be strings');
            }
            $patterns[] = $pattern;
        }
        return CostBudget::fromPatterns($patterns);
    }
}
