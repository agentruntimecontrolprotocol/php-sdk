<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/**
 * ARCP v1.1 §7.1 — submit a job to an agent: `{agent, input,
 * lease_request?, lease_constraints?, idempotency_key?,
 * max_runtime_sec?}`. The `agent` reference may pin a version with the
 * `name@version` form (§7.5).
 */
final readonly class JobSubmit extends MessageType
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $leaseRequest §9.2 capability map.
     * @param array<string, mixed>|null $leaseConstraints e.g. `{expires_at}`.
     *
     * @size-check-suppress Wire-shape DTO mapping to §7.1.
     */
    public function __construct(
        public string $agent,
        public array $input = [],
        public ?array $leaseRequest = null,
        public ?array $leaseConstraints = null,
        public ?string $idempotencyKey = null,
        public ?int $maxRuntimeSec = null,
    ) {
        if ($agent === '') {
            throw new InvalidRequestException('job.submit agent missing');
        }
        if ($maxRuntimeSec !== null && $maxRuntimeSec <= 0) {
            throw new InvalidRequestException('job.submit max_runtime_sec must be positive');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.submit';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['agent' => $this->agent, 'input' => $this->input];
        if ($this->leaseRequest !== null) {
            $out['lease_request'] = $this->leaseRequest;
        }
        if ($this->leaseConstraints !== null) {
            $out['lease_constraints'] = $this->leaseConstraints;
        }
        if ($this->idempotencyKey !== null) {
            $out['idempotency_key'] = $this->idempotencyKey;
        }
        if ($this->maxRuntimeSec !== null) {
            $out['max_runtime_sec'] = $this->maxRuntimeSec;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $agent = $data['agent'] ?? throw new InvalidRequestException('job.submit agent missing');
        if (!\is_string($agent)) {
            throw new InvalidRequestException('job.submit agent must be string');
        }
        return new self(
            $agent,
            self::optionalMap($data, 'input') ?? [],
            self::optionalMap($data, 'lease_request'),
            self::optionalMap($data, 'lease_constraints'),
            self::optionalString($data, 'idempotency_key'),
            self::optionalInt($data, 'max_runtime_sec'),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private static function optionalMap(array $data, string $key): ?array
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!\is_array($data[$key])) {
            throw new InvalidRequestException(\sprintf('job.submit %s must be object', $key));
        }
        /** @var array<string, mixed> $map */
        $map = $data[$key];
        return $map;
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!\is_string($data[$key])) {
            throw new InvalidRequestException(\sprintf('job.submit %s must be string', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function optionalInt(array $data, string $key): ?int
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!\is_int($data[$key])) {
            throw new InvalidRequestException(\sprintf('job.submit %s must be integer', $key));
        }
        return $data[$key];
    }
}
