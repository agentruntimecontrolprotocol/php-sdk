<?php

declare(strict_types=1);

namespace Arcp\Runtime\Credentials;

use Arcp\Errors\InvalidArgumentException;
use Arcp\Messages\Permissions\LeaseGranted;

/** ARCP v1.1 §9.8.1 provisioned credential wire DTO. */
final readonly class Credential
{
    /** @param array<string, mixed>|null $constraints */
    public function __construct(
        public string $id,
        public string $scheme,
        public string $value,
        public string $endpoint,
        public ?string $profile = null,
        public ?array $constraints = null,
    ) {
        if ($id === '' || $scheme === '' || $value === '' || $endpoint === '') {
            throw new InvalidArgumentException('credential id/scheme/value/endpoint must be non-empty');
        }
    }

    public static function withLeaseConstraints(
        string $id,
        string $value,
        string $endpoint,
        LeaseGranted $lease,
        string $scheme = 'bearer',
        ?string $profile = null,
    ): self {
        return new self($id, $scheme, $value, $endpoint, $profile, self::constraintsFromLease($lease));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'scheme' => $this->scheme,
            'value' => $this->value,
            'endpoint' => $this->endpoint,
        ];
        if ($this->profile !== null) {
            $out['profile'] = $this->profile;
        }
        if ($this->constraints !== null) {
            $out['constraints'] = $this->constraints;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function toRedactedArray(): array
    {
        return [...$this->toArray(), 'value' => '***'];
    }

    /** @return array<string, mixed> */
    public static function constraintsFromLease(LeaseGranted $lease): array
    {
        $constraints = [
            'lease_constraints' => [
                'expires_at' => $lease->expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            ],
        ];
        if ($lease->costBudget !== null) {
            $constraints['cost.budget'] = $lease->costBudget->patterns();
        }
        if ($lease->modelUse !== null) {
            $constraints['model.use'] = $lease->modelUse->patterns;
        }
        return $constraints;
    }
}
