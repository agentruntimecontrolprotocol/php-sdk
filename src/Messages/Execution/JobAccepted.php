<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\JobId;
use Arcp\Ids\TraceId;

/**
 * ARCP v1.1 §7.1 — runtime accepted the job: `{job_id, agent, lease,
 * lease_constraints, budget, credentials, accepted_at, trace_id}`.
 * `agent` carries the resolved `name@version` reference (§7.5).
 */
final readonly class JobAccepted extends MessageType
{
    /**
     * @param array<string, mixed>|null $lease Effective lease (§9.2).
     * @param array<string, mixed>|null $leaseConstraints e.g. `{expires_at}`.
     * @param array<string, float>|null $budget Initial §9.6 budget counters.
     * @param list<array<string, mixed>>|null $credentials §9.8 provisioned credentials.
     *
     * @size-check-suppress Wire-shape DTO mapping to §7.1.
     */
    public function __construct(
        public JobId $jobId,
        public string $agent,
        public ?array $lease = null,
        public ?array $leaseConstraints = null,
        public ?array $budget = null,
        public ?array $credentials = null,
        public ?\DateTimeImmutable $acceptedAt = null,
        public ?TraceId $traceId = null,
    ) {
        if ($agent === '') {
            throw new InvalidRequestException('job.accepted agent missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.accepted';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'job_id' => (string) $this->jobId,
            'agent' => $this->agent,
        ];
        if ($this->lease !== null) {
            $out['lease'] = $this->lease;
        }
        if ($this->leaseConstraints !== null) {
            $out['lease_constraints'] = $this->leaseConstraints;
        }
        if ($this->budget !== null) {
            $out['budget'] = $this->budget;
        }
        if ($this->credentials !== null) {
            $out['credentials'] = $this->credentials;
        }
        if ($this->acceptedAt instanceof \DateTimeImmutable) {
            $out['accepted_at'] = $this->acceptedAt->format(\DateTimeInterface::RFC3339_EXTENDED);
        }
        if ($this->traceId instanceof TraceId) {
            $out['trace_id'] = (string) $this->traceId;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $jobId = $data['job_id'] ?? throw new InvalidRequestException('job.accepted job_id missing');
        $agent = $data['agent'] ?? throw new InvalidRequestException('job.accepted agent missing');
        if (!\is_string($agent)) {
            throw new InvalidRequestException('job.accepted agent must be string');
        }
        return new self(
            JobId::fromJson($jobId),
            $agent,
            self::optionalMap($data, 'lease'),
            self::optionalMap($data, 'lease_constraints'),
            self::budgetFromArray($data),
            self::credentialsFromArray($data),
            self::acceptedAtFromArray($data),
            isset($data['trace_id']) ? TraceId::fromJson($data['trace_id']) : null,
        );
    }

    /** Copy with plaintext credential values masked, for logging surfaces. */
    public function redacted(): self
    {
        if ($this->credentials === null) {
            return $this;
        }
        $credentials = [];
        foreach ($this->credentials as $credential) {
            $credentials[] = [...$credential, 'value' => '***'];
        }
        return new self(
            $this->jobId,
            $this->agent,
            $this->lease,
            $this->leaseConstraints,
            $this->budget,
            $credentials,
            $this->acceptedAt,
            $this->traceId,
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
            throw new InvalidRequestException(\sprintf('job.accepted %s must be object', $key));
        }
        /** @var array<string, mixed> $map */
        $map = $data[$key];
        return $map;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, float>|null
     */
    private static function budgetFromArray(array $data): ?array
    {
        if (!isset($data['budget'])) {
            return null;
        }
        if (!\is_array($data['budget'])) {
            throw new InvalidRequestException('job.accepted budget must be object');
        }
        $budget = [];
        foreach ($data['budget'] as $currency => $amount) {
            if (!\is_string($currency) || (!\is_int($amount) && !\is_float($amount))) {
                throw new InvalidRequestException('job.accepted budget entries must be currency => number');
            }
            $budget[$currency] = (float) $amount;
        }
        return $budget;
    }

    /** @param array<string, mixed> $data */
    private static function acceptedAtFromArray(array $data): ?\DateTimeImmutable
    {
        if (!isset($data['accepted_at'])) {
            return null;
        }
        if (!\is_string($data['accepted_at'])) {
            throw new InvalidRequestException('job.accepted accepted_at must be string');
        }
        try {
            return new \DateTimeImmutable($data['accepted_at']);
        } catch (\DateMalformedStringException $e) {
            throw new InvalidRequestException(
                'job.accepted accepted_at must be RFC 3339',
                ['accepted_at' => $data['accepted_at']],
                null,
                $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>|null
     */
    private static function credentialsFromArray(array $data): ?array
    {
        if (!isset($data['credentials'])) {
            return null;
        }
        if (!\is_array($data['credentials'])) {
            throw new InvalidRequestException('credentials must be list');
        }
        $credentials = [];
        foreach ($data['credentials'] as $credential) {
            if (!\is_array($credential)) {
                throw new InvalidRequestException('credential entries must be objects');
            }
            /** @var array<string, mixed> $credential */
            $credentials[] = $credential;
        }
        return $credentials;
    }
}
