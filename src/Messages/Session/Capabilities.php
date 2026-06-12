<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Errors\InvalidRequestException;

/**
 * Capability set negotiated during session establishment (ARCP v1.1 §6.2).
 *
 * The wire shape is `{encodings, features, agents?}`:
 *   - `encodings` — supported payload encodings (`["json"]` by default);
 *   - `features` — optional v1.1 feature-flag strings (`heartbeat`,
 *     `ack`, `list_jobs`, `subscribe`, ...);
 *   - `agents` — the §7.5 agent inventory, advertised by the runtime in
 *     `session.welcome` only.
 *
 * The effective feature set is the intersection of the `session.hello`
 * and `session.welcome` features; either peer MUST NOT use a feature
 * outside that intersection. Unknown vendor-namespaced keys round-trip
 * through `extra` so deployments can carry extension capability values.
 */
final readonly class Capabilities
{
    /**
     * @param list<string> $encodings Supported payload encodings (§6.2).
     * @param list<string> $features Named v1.1 feature flags (§6.2).
     * @param list<array<string, mixed>>|null $agents §7.5 agent inventory
     *                                                (welcome only); entries are `{name, versions, default?}`.
     * @param array<string, mixed> $extra Vendor-namespaced passthrough keys.
     */
    public function __construct(
        public array $encodings = ['json'],
        public array $features = [],
        public ?array $agents = null,
        public array $extra = [],
    ) {
        if ($encodings === []) {
            throw new InvalidRequestException('capabilities.encodings must not be empty');
        }
    }

    private const array KNOWN_KEYS = ['encodings', 'features', 'agents'];

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'encodings' => $this->encodings,
            'features' => $this->features,
        ];
        if ($this->agents !== null) {
            $out['agents'] = $this->agents;
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
        return new self(
            encodings: self::stringListField($data, 'encodings', ['json']),
            features: self::stringListField($data, 'features', []),
            agents: self::agentsField($data),
            extra: self::extraFromArray($data),
        );
    }

    public function hasFeature(string $feature): bool
    {
        return \in_array($feature, $this->features, true);
    }

    /** @param list<array<string, mixed>>|null $agents */
    public function withAgents(?array $agents): self
    {
        return new self($this->encodings, $this->features, $agents, $this->extra);
    }

    /** @param list<string> $features */
    public function withFeatures(array $features): self
    {
        return new self(
            $this->encodings,
            array_values(array_unique($features)),
            $this->agents,
            $this->extra,
        );
    }

    /**
     * §6.2 intersection semantics: the effective capability set shared by
     * both peers. Encodings and features are intersected (preserving this
     * side's order); the agent inventory and extras of `$this` (the
     * runtime side) are kept.
     */
    public function intersect(self $requested): self
    {
        $encodings = array_values(array_intersect($this->encodings, $requested->encodings));
        return new self(
            $encodings === [] ? ['json'] : $encodings,
            array_values(array_intersect($this->features, $requested->features)),
            $this->agents,
            $this->extra,
        );
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
        return $out === [] && $key === 'encodings' ? $default : $out;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>|null
     */
    private static function agentsField(array $data): ?array
    {
        if (!isset($data['agents']) || !\is_array($data['agents'])) {
            return null;
        }
        $out = [];
        foreach ($data['agents'] as $agent) {
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

    /** Default capability set advertised by this runtime (§6.2). */
    public static function defaultRuntime(): self
    {
        return new self(
            encodings: ['json'],
            // §6.2: advertise the v1.1 feature flags this runtime actually
            // backs so the negotiated intersection is non-empty.
            // `provisioned_credentials`/`model.use` are injected per-session
            // by advertisedCapabilitiesForSession() when a provisioner exists.
            features: [
                'heartbeat',
                'ack',
                'list_jobs',
                'subscribe',
                'lease_expires_at',
                'cost.budget',
                'progress',
                'result_chunk',
                'agent_versions',
            ],
        );
    }
}
