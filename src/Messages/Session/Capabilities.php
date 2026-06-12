<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Errors\InvalidRequestException;

/**
 * Capability set negotiated during session establishment (RFC §7).
 *
 * Booleans default to `false` per RFC §7. Unrecognized fields are stored
 * verbatim in `extra` so a future revision can add fields without
 * breaking older runtimes.
 *
 * @phpstan-type ExtraMap array<string, mixed>
 */
final readonly class Capabilities
{
    /**
     * @param list<string> $extensions Advertised extension type-namespaces.
     * @param list<string> $features Named v1.1 feature flags.
     * @param list<string> $binaryEncodings Per RFC §11.3 (`base64`, `sidecar`).
     * @param list<string|array<string, mixed>> $agents Runtime agent inventory.
     * @param ExtraMap $extra
     *
     * @size-check-suppress Wire-shape DTO mapping to RFC §7.
     */
    public function __construct(
        public bool $streaming = false,
        public bool $durableJobs = false,
        public bool $checkpoints = false,
        public bool $binaryStreams = false,
        public bool $agentHandoff = false,
        public bool $humanInput = false,
        public bool $artifacts = false,
        public bool $subscriptions = false,
        public bool $scheduledJobs = false,
        public bool $interrupt = false,
        public bool $anonymous = false,
        public int $heartbeatIntervalSeconds = 30,
        public string $heartbeatRecovery = 'fail',
        public array $binaryEncodings = ['base64'],
        public array $extensions = [],
        public array $features = [],
        public array $agents = [],
        public ?int $artifactRetentionDefaultSeconds = null,
        public ?int $artifactRetentionMaxSeconds = null,
        public array $extra = [],
    ) {
        if ($heartbeatIntervalSeconds < 1) {
            throw new InvalidRequestException('heartbeat_interval_seconds must be ≥ 1');
        }
        if ($heartbeatRecovery !== 'fail' && $heartbeatRecovery !== 'block') {
            throw new InvalidRequestException('heartbeat_recovery must be "fail" or "block"');
        }
    }

    private const array KNOWN_KEYS = [
        'streaming', 'durable_jobs', 'checkpoints', 'binary_streams', 'agent_handoff',
        'human_input', 'artifacts', 'subscriptions', 'scheduled_jobs', 'interrupt',
        'anonymous', 'heartbeat_interval_seconds', 'heartbeat_recovery',
        'binary_encoding', 'extensions', 'features', 'agents', 'artifact_retention',
    ];

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = $this->booleansToArray();
        $out['heartbeat_interval_seconds'] = $this->heartbeatIntervalSeconds;
        $out['heartbeat_recovery'] = $this->heartbeatRecovery;
        $out['binary_encoding'] = $this->binaryEncodings;
        $out['extensions'] = $this->extensions;
        if ($this->features !== []) {
            $out['features'] = $this->features;
        }
        if ($this->agents !== []) {
            $out['agents'] = $this->agents;
        }
        $retention = $this->retentionToArray();
        if ($retention !== null) {
            $out['artifact_retention'] = $retention;
        }
        foreach ($this->extra as $k => $v) {
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        [$defaultRet, $maxRet] = self::retentionFromArray($data);
        return new self(
            streaming: self::boolField($data, 'streaming'),
            durableJobs: self::boolField($data, 'durable_jobs'),
            checkpoints: self::boolField($data, 'checkpoints'),
            binaryStreams: self::boolField($data, 'binary_streams'),
            agentHandoff: self::boolField($data, 'agent_handoff'),
            humanInput: self::boolField($data, 'human_input'),
            artifacts: self::boolField($data, 'artifacts'),
            subscriptions: self::boolField($data, 'subscriptions'),
            scheduledJobs: self::boolField($data, 'scheduled_jobs'),
            interrupt: self::boolField($data, 'interrupt'),
            anonymous: self::boolField($data, 'anonymous'),
            heartbeatIntervalSeconds: self::intField($data, 'heartbeat_interval_seconds', 30),
            heartbeatRecovery: self::stringField($data, 'heartbeat_recovery', 'fail'),
            binaryEncodings: self::stringListField($data, 'binary_encoding', ['base64']),
            extensions: self::stringListField($data, 'extensions', []),
            features: self::stringListField($data, 'features', []),
            agents: self::agentsField($data),
            artifactRetentionDefaultSeconds: $defaultRet,
            artifactRetentionMaxSeconds: $maxRet,
            extra: self::extraFromArray($data),
        );
    }

    /**
     * @param list<string|array<string, mixed>> $agents
     */
    public function withAgents(array $agents): self
    {
        return new self(
            streaming: $this->streaming,
            durableJobs: $this->durableJobs,
            checkpoints: $this->checkpoints,
            binaryStreams: $this->binaryStreams,
            agentHandoff: $this->agentHandoff,
            humanInput: $this->humanInput,
            artifacts: $this->artifacts,
            subscriptions: $this->subscriptions,
            scheduledJobs: $this->scheduledJobs,
            interrupt: $this->interrupt,
            anonymous: $this->anonymous,
            heartbeatIntervalSeconds: $this->heartbeatIntervalSeconds,
            heartbeatRecovery: $this->heartbeatRecovery,
            binaryEncodings: $this->binaryEncodings,
            extensions: $this->extensions,
            features: $this->features,
            agents: $agents,
            artifactRetentionDefaultSeconds: $this->artifactRetentionDefaultSeconds,
            artifactRetentionMaxSeconds: $this->artifactRetentionMaxSeconds,
            extra: $this->extra,
        );
    }

    /** @param list<string> $features */
    public function withFeatures(array $features): self
    {
        return new self(
            streaming: $this->streaming,
            durableJobs: $this->durableJobs,
            checkpoints: $this->checkpoints,
            binaryStreams: $this->binaryStreams,
            agentHandoff: $this->agentHandoff,
            humanInput: $this->humanInput,
            artifacts: $this->artifacts,
            subscriptions: $this->subscriptions,
            scheduledJobs: $this->scheduledJobs,
            interrupt: $this->interrupt,
            anonymous: $this->anonymous,
            heartbeatIntervalSeconds: $this->heartbeatIntervalSeconds,
            heartbeatRecovery: $this->heartbeatRecovery,
            binaryEncodings: $this->binaryEncodings,
            extensions: $this->extensions,
            features: array_values(array_unique($features)),
            agents: $this->agents,
            artifactRetentionDefaultSeconds: $this->artifactRetentionDefaultSeconds,
            artifactRetentionMaxSeconds: $this->artifactRetentionMaxSeconds,
            extra: $this->extra,
        );
    }

    /** @return array<string, bool> */
    private function booleansToArray(): array
    {
        return [
            'streaming' => $this->streaming,
            'durable_jobs' => $this->durableJobs,
            'checkpoints' => $this->checkpoints,
            'binary_streams' => $this->binaryStreams,
            'agent_handoff' => $this->agentHandoff,
            'human_input' => $this->humanInput,
            'artifacts' => $this->artifacts,
            'subscriptions' => $this->subscriptions,
            'scheduled_jobs' => $this->scheduledJobs,
            'interrupt' => $this->interrupt,
            'anonymous' => $this->anonymous,
        ];
    }

    /** @return array<string, int>|null */
    private function retentionToArray(): ?array
    {
        if ($this->artifactRetentionDefaultSeconds === null
            && $this->artifactRetentionMaxSeconds === null) {
            return null;
        }
        $retention = [];
        if ($this->artifactRetentionDefaultSeconds !== null) {
            $retention['default_seconds'] = $this->artifactRetentionDefaultSeconds;
        }
        if ($this->artifactRetentionMaxSeconds !== null) {
            $retention['max_seconds'] = $this->artifactRetentionMaxSeconds;
        }
        return $retention;
    }

    /** @param array<string, mixed> $data */
    private static function boolField(array $data, string $key): bool
    {
        return isset($data[$key]) && $data[$key] === true;
    }

    /** @param array<string, mixed> $data */
    private static function intField(array $data, string $key, int $default): int
    {
        return isset($data[$key]) && \is_int($data[$key]) ? $data[$key] : $default;
    }

    /** @param array<string, mixed> $data */
    private static function stringField(array $data, string $key, string $default): string
    {
        return isset($data[$key]) && \is_string($data[$key]) ? $data[$key] : $default;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $default
     *
     * @return list<string>
     */
    private static function stringListField(array $data, string $key, array $default): array
    {
        if (!isset($data[$key]) || !\is_array($data[$key])) {
            return $default;
        }
        $out = [];
        foreach ($data[$key] as $v) {
            if (\is_string($v)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string|array<string, mixed>>
     */
    private static function agentsField(array $data): array
    {
        if (!isset($data['agents']) || !\is_array($data['agents'])) {
            return [];
        }
        $out = [];
        foreach ($data['agents'] as $agent) {
            if (\is_string($agent)) {
                $out[] = $agent;
                continue;
            }
            if (\is_array($agent)) {
                /** @var array<string, mixed> $agent */
                $out[] = $agent;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function retentionFromArray(array $data): array
    {
        if (!isset($data['artifact_retention']) || !\is_array($data['artifact_retention'])) {
            return [null, null];
        }
        $defaultSec = $data['artifact_retention']['default_seconds'] ?? null;
        $maxSec = $data['artifact_retention']['max_seconds'] ?? null;
        return [
            \is_int($defaultSec) ? $defaultSec : null,
            \is_int($maxSec) ? $maxSec : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function extraFromArray(array $data): array
    {
        $extra = [];
        foreach ($data as $k => $v) {
            if (!\in_array($k, self::KNOWN_KEYS, true)) {
                $extra[$k] = $v;
            }
        }
        return $extra;
    }

    /** Default capabilities advertised by this implementation (PLAN.md §1 / §7). */
    public static function defaultRuntime(): self
    {
        return new self(
            streaming: true,
            durableJobs: true,
            humanInput: true,
            artifacts: true,
            subscriptions: true,
            interrupt: true,
            heartbeatIntervalSeconds: 30,
            heartbeatRecovery: 'fail',
            binaryEncodings: ['base64'],
            // §6.2: advertise the v1.1 feature flags this runtime actually
            // backs so the negotiated intersection is non-empty. `ack` is
            // intentionally omitted (only partially implemented);
            // `provisioned_credentials`/`model.use` are injected per-session
            // by advertisedCapabilitiesForSession() when a provisioner exists.
            features: [
                'heartbeat',
                'list_jobs',
                'subscribe',
                'lease_expires_at',
                'cost.budget',
                'progress',
                'result_chunk',
                'agent_versions',
            ],
            artifactRetentionDefaultSeconds: 86400,
            artifactRetentionMaxSeconds: 604800,
        );
    }
}
