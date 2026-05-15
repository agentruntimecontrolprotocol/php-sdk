<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Errors\InvalidArgumentException;

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
     * @param list<string> $binaryEncodings Per RFC §11.3 (`base64`, `sidecar`).
     * @param ExtraMap $extra
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
        public ?int $artifactRetentionDefaultSeconds = null,
        public ?int $artifactRetentionMaxSeconds = null,
        public array $extra = [],
    ) {
        if ($heartbeatIntervalSeconds < 1) {
            throw new InvalidArgumentException('heartbeat_interval_seconds must be ≥ 1');
        }
        if ($heartbeatRecovery !== 'fail' && $heartbeatRecovery !== 'block') {
            throw new InvalidArgumentException('heartbeat_recovery must be "fail" or "block"');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
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
            'heartbeat_interval_seconds' => $this->heartbeatIntervalSeconds,
            'heartbeat_recovery' => $this->heartbeatRecovery,
            'binary_encoding' => $this->binaryEncodings,
            'extensions' => $this->extensions,
        ];
        $hasRetention = $this->artifactRetentionDefaultSeconds !== null
            || $this->artifactRetentionMaxSeconds !== null;
        if ($hasRetention) {
            $retention = [];
            if ($this->artifactRetentionDefaultSeconds !== null) {
                $retention['default_seconds'] = $this->artifactRetentionDefaultSeconds;
            }
            if ($this->artifactRetentionMaxSeconds !== null) {
                $retention['max_seconds'] = $this->artifactRetentionMaxSeconds;
            }
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
        $bool = static fn (string $k, bool $default = false): bool
            => isset($data[$k]) && $data[$k] === true;
        $extensions = [];
        if (isset($data['extensions']) && \is_array($data['extensions'])) {
            foreach ($data['extensions'] as $ext) {
                if (\is_string($ext)) {
                    $extensions[] = $ext;
                }
            }
        }
        $binaryEncodings = ['base64'];
        if (isset($data['binary_encoding']) && \is_array($data['binary_encoding'])) {
            $binaryEncodings = [];
            foreach ($data['binary_encoding'] as $enc) {
                if (\is_string($enc)) {
                    $binaryEncodings[] = $enc;
                }
            }
        }

        $heartbeat = 30;
        if (
            isset($data['heartbeat_interval_seconds'])
            && \is_int($data['heartbeat_interval_seconds'])
        ) {
            $heartbeat = $data['heartbeat_interval_seconds'];
        }

        $recovery = 'fail';
        if (isset($data['heartbeat_recovery']) && \is_string($data['heartbeat_recovery'])) {
            $recovery = $data['heartbeat_recovery'];
        }

        $defaultRet = null;
        $maxRet = null;
        if (isset($data['artifact_retention']) && \is_array($data['artifact_retention'])) {
            $defaultSec = $data['artifact_retention']['default_seconds'] ?? null;
            if (\is_int($defaultSec)) {
                $defaultRet = $defaultSec;
            }
            $maxSec = $data['artifact_retention']['max_seconds'] ?? null;
            if (\is_int($maxSec)) {
                $maxRet = $maxSec;
            }
        }

        $known = [
            'streaming', 'durable_jobs', 'checkpoints', 'binary_streams', 'agent_handoff',
            'human_input', 'artifacts', 'subscriptions', 'scheduled_jobs', 'interrupt',
            'anonymous', 'heartbeat_interval_seconds', 'heartbeat_recovery',
            'binary_encoding', 'extensions', 'artifact_retention',
        ];
        $extra = [];
        foreach ($data as $k => $v) {
            if (!\in_array($k, $known, true)) {
                $extra[$k] = $v;
            }
        }

        return new self(
            streaming: $bool('streaming'),
            durableJobs: $bool('durable_jobs'),
            checkpoints: $bool('checkpoints'),
            binaryStreams: $bool('binary_streams'),
            agentHandoff: $bool('agent_handoff'),
            humanInput: $bool('human_input'),
            artifacts: $bool('artifacts'),
            subscriptions: $bool('subscriptions'),
            scheduledJobs: $bool('scheduled_jobs'),
            interrupt: $bool('interrupt'),
            anonymous: $bool('anonymous'),
            heartbeatIntervalSeconds: $heartbeat,
            heartbeatRecovery: $recovery,
            binaryEncodings: $binaryEncodings,
            extensions: $extensions,
            artifactRetentionDefaultSeconds: $defaultRet,
            artifactRetentionMaxSeconds: $maxRet,
            extra: $extra,
        );
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
            artifactRetentionDefaultSeconds: 86400,
            artifactRetentionMaxSeconds: 604800,
        );
    }
}
