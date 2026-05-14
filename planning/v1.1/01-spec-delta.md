# 01 — ARCP v1.1 Spec Delta

Source: `../spec/docs/draft-arcp-02.1.md`. v1.1 is **additive** —
a v1.0 PHP client speaking to a v1.1 runtime keeps working, and a
v1.1 PHP client downgrades against a v1.0 runtime via the feature
intersection rule in §6.2.

## 1. Additions table

Columns:

- **§** — spec section.
- **Feature flag** — string sent in
  `session.hello.payload.capabilities.features` and echoed in
  `session.welcome.payload.capabilities.features`. The effective
  set is the intersection (§6.2).
- **Norm** — MUST / SHOULD / MAY for the wire-level requirement on
  the side advertising the feature.
- **Client-side impact** — what the PHP client implementation must
  gain to use the feature.
- **Runtime-side impact** — what `Arcp\Runtime` must gain to honor
  the feature.
- **Compat** — `additive` (existing v1.0 code paths still work
  unchanged when feature absent) or `breaking` (changes a v1.0
  invariant). All v1.1 changes are additive for a v1.0 client; the
  table marks _internal_ breaking changes where a v1.1 PHP runtime
  must serve a v1.0 client without regression.

| §       | Feature flag        | Message / shape                                                                              | Norm           | Client-side                                                                                             | Runtime-side                                                                                          | Compat                                |
| ------- | ------------------- | -------------------------------------------------------------------------------------------- | -------------- | ------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------- |
| §6.2    | (negotiation)       | `capabilities.features: string[]` in `session.hello` + `session.welcome`; agents rich object | MUST           | Send `features` list; intersect with welcome; refuse to emit messages outside intersection.             | Echo intersection; advertise `agents` rich shape unconditionally; never emit unnegotiated features.   | additive                              |
| §6.4    | `heartbeat`         | `session.ping` / `session.pong`; `welcome.heartbeat_interval_sec`                            | SHOULD send    | Idle timer per interval; respond to ping within interval; close + `HEARTBEAT_LOST` after 2× silence.    | Same as client; **MUST NOT** terminate jobs on heartbeat loss — session lives through resume window.  | additive                              |
| §6.5    | `ack`               | `session.ack { last_processed_seq }`                                                         | client MAY     | Send ack at most per event or per few-hundred ms — whichever is less frequent.                          | MAY free buffer ≤ ack early; MUST NOT free unacked events even if window elapsed unless OOM pressure. | additive                              |
| §6.6    | `list_jobs`         | `session.list_jobs` request → `session.jobs` response, cursored                              | MUST honor     | Issue request, page on `next_cursor`; render filter (status/agent/created_after).                       | Enforce per-principal visibility; never leak jobs from unrelated principals.                          | additive                              |
| §7.5    | `agent_versions`    | `agent ::= name \| name "@" version`; `welcome.agents[i] = { name, versions, default }`      | MUST resolve   | Accept `name@version`; surface available versions from welcome; pin version to avoid drift.             | Resolve bare name → default; reject unknown version with `AGENT_VERSION_NOT_AVAILABLE`; never migrate.| additive                              |
| §7.6    | `subscribe`         | `job.subscribe { job_id, from_event_seq?, history? }` → `job.subscribed`                     | MUST authorize | Public seam to attach to a job; expose as a `Pipeline` of events, no cancel authority.                  | Verify principal can observe; replay buffered events when `history: true`; flow into session stream.  | additive                              |
| §8.2.1  | `progress`          | `kind: "progress" { current, total?, units?, message? }`                                     | MAY emit       | Decode → typed `ProgressEvent` value object; advisory only.                                             | Pass-through; protocol takes no action.                                                               | additive                              |
| §8.4    | `result_chunk`      | `kind: "result_chunk" { result_id, chunk_seq, data, encoding, more }` → `job.result.result_id` | MUST NOT mix | Iterator-style consumption (`Generator<string>` decoded by `encoding`); assert monotonic `chunk_seq`.    | When agent streams, allocate `result_id`; terminate with `job.result.result_id`; reject inline mix.   | additive                              |
| §9.5    | `lease_expires_at`  | `job.submit.payload.lease_constraints.expires_at`                                            | MUST enforce   | Send ISO-8601 UTC `Z`; reject local-offset stamps client-side before submit.                            | Evaluate on every authority op; emit `LEASE_EXPIRED` (retryable: false); MAY pre-terminate expired.   | additive                              |
| §9.6    | `cost.budget`       | `cost.budget: ["CCY:amount", …]`; `metric { name: cost.*, value, unit }` decrements          | MUST enforce   | Encode amount strings; surface budget state via opt-in `cost.budget.remaining` metrics.                 | Per-currency counter; check before every authority op; `BUDGET_EXHAUSTED` (retryable: false).         | additive                              |
| §9.4    | (delegation rules)  | Child `cost.budget` ≤ parent remaining; child `expires_at` ≤ parent `expires_at`             | MUST           | When PHP code is a delegating agent, compute the bounded child lease.                                   | Enforce subsetting on `delegate` envelope; reject violators with `LEASE_SUBSET_VIOLATION`.            | additive                              |
| §11     | (trace attrs)       | `arcp.lease.expires_at`, `arcp.budget.remaining` span attributes                             | SHOULD         | OTEL middleware emits both attributes when the lease carries them.                                      | Same on runtime side.                                                                                 | additive                              |
| §12     | (error codes)       | `AGENT_VERSION_NOT_AVAILABLE`, `LEASE_EXPIRED`, `BUDGET_EXHAUSTED`                           | MUST           | New `final` subclasses of `Arcp\Errors\ArcpException`; `getCode()` returns spec string.                 | Emit with `retryable: false`; surface via `tool_result` for budget where the agent can recover.       | _internal-breaking_: error enum grows |

## 2. Three new error codes (§12)

All three appear in `payload.error.code` and map to a dedicated
`final` exception under `Arcp\Errors\` (target namespace; not the
current `src/Errors/` taxonomy — see Phase 02 audit).

| Spec code                     | PHP class (target)                | Source seam                                                                                                                 | `retryable` | Notes                                                                              |
| ----------------------------- | --------------------------------- | --------------------------------------------------------------------------------------------------------------------------- | ----------- | ---------------------------------------------------------------------------------- |
| `AGENT_VERSION_NOT_AVAILABLE` | `AgentVersionNotAvailableException` | `job.accepted` / `job.error` when `name@version` requested but version not registered (§7.5).                              | false       | Distinct from `AGENT_NOT_AVAILABLE`: the name resolved but the pinned version did not. |
| `LEASE_EXPIRED`               | `LeaseExpiredException`           | `tool_result` (per op) or `job.error` (`final_status: "error"`) when an authority op runs at or after `expires_at` (§9.5).  | false       | A naive retry from the same submitting client will fail identically — submit again with a fresh lease. |
| `BUDGET_EXHAUSTED`            | `BudgetExhaustedException`        | `tool_result` (preferred — agent can react) or `job.error` if runtime treats exhaustion as fatal (§9.6).                    | false       | Counters are per-currency; one currency hitting zero blocks all authority ops.     |

PHP target: each class is `final` and `readonly`, extends
`ArcpException`, constructor-promoted `string $message`, optional
`?array $details`, with a class constant `public const string CODE`
returning the spec wire string. Mirrors the existing
`Arcp\Errors\ErrorCode` enum pattern but tightens it to one class
per code rather than a code-grab-bag.

Existing `src/Errors/` carries gRPC-shaped exceptions
(`AbortedException`, `DataLossException`, …) that do not match the
v1.0 / v1.1 wire taxonomy of 12 / 15 codes. That mismatch is a v1.0
conformance issue Phase 02 must call out — not a v1.1 task — but the
v1.1 work will not extend the gRPC-shaped pile, it adds the three
spec-named exceptions and lets Phase 02 retire the unused ones.

## 3. Capability negotiation (§6.2)

The wire mechanics are mechanical; the **PHP shape** is what
matters here. Three artifacts drive every other piece of work:

### 3.1. `Feature` enum

```
namespace Arcp\Session;

enum Feature: string {
    case Heartbeat        = 'heartbeat';
    case Ack              = 'ack';
    case ListJobs         = 'list_jobs';
    case Subscribe        = 'subscribe';
    case LeaseExpiresAt   = 'lease_expires_at';
    case CostBudget       = 'cost.budget';
    case Progress         = 'progress';
    case ResultChunk      = 'result_chunk';
    case AgentVersions    = 'agent_versions';
}
```

Closed enum (no `unknown` case) so a v1.2 feature flag arriving on
the wire is **dropped** at decode rather than passed up the stack —
forward-compat for the runtime is "ignore what you don't know," but
the type system MUST NOT silently grow.

### 3.2. `CapabilitySet` value object

```
final readonly class CapabilitySet {
    /** @param list<Feature> $features */
    public function __construct(
        public array $features,
        public array $encodings = ['json'],
        public ?AgentInventory $agents = null,
    ) {}

    public function intersect(self $other): self { /* set ∩ */ }
    public function supports(Feature $f): bool   { /* in set    */ }
}
```

Intersection lives on the value object, not on `Session`, so it can
be unit-tested without I/O. Every place the SDK considers emitting
a feature-gated message goes through `$session->capabilities->supports($f)`.

### 3.3. `AgentInventory` rich shape (§7.5)

```
final readonly class AgentInventory {
    /** @param list<AgentEntry> $entries */
    public function __construct(public array $entries) {}
    public function default(string $name): ?string { … }
    public function versions(string $name): array  { … }
}

final readonly class AgentEntry {
    /** @param list<string> $versions */
    public function __construct(
        public string $name,
        public array  $versions,
        public ?string $default,
    ) {}

    public static function fromArray(array $a): self { … }
    /** v1.0 flat string entry → AgentEntry with no versions */
    public static function fromFlat(string $name): self { … }
}
```

`fromFlat` is the v1.0 compat path: a v1.0 runtime returning
`agents: ["code-refactor", …]` decodes into entries with empty
`versions` and `default = null`, and any v1.1 client code that tries
`name@version` against such a runtime fails fast with a typed error
rather than crashing decode.

### 3.4. Negotiation lifecycle

1. PHP client builds `CapabilitySet` from compiled-in `Feature::cases()`.
2. Encodes into `session.hello.payload.capabilities`.
3. Decodes `session.welcome.payload.capabilities`; constructs the
   runtime's `CapabilitySet`.
4. Stores `effective = $client->intersect($runtime)` on the
   `Session` value object (immutable post-welcome).
5. Every feature-gated send-site `assert`s `effective->supports($f)`
   in dev (PHPStan max forbids the unguarded call); in prod the
   guard throws `UnnegotiatedFeatureException` (not a wire code —
   library-internal; bug if it ever surfaces).

## 4. Scope boundary — what v1.1 is NOT

These are explicitly deferred per spec "Not in v1.1":

- Job pause / unpause.
- Job priority and scheduling hints.
- Federation across runtimes.
- Streaming-token surface for LLM outputs (separate from
  `result_chunk`, which is final-result streaming).

Do not let Phase 04 architecture pre-bake hooks for these. A future
`Feature::JobPause` case can be added when v1.2 lands without
touching anything except the enum and the new code path. Pre-baking
generic "extension points" is the kind of speculative
generalization the bootstrap rules ban — `match (Feature)` is
exhaustive, the compiler tells you when to add a branch, that is
the extension mechanism.

## 5. Reference index for downstream phases

| Phase                 | Spec § to keep open                                            |
| --------------------- | -------------------------------------------------------------- |
| 03 libraries          | §4 (transport), §5 (wire format)                               |
| 04 architecture       | §6.2, §6.4, §6.5, §7.5, §7.6, §8.4, §9.5, §9.6, §11, §12       |
| 05 middleware         | §4 (WS), §6.4 (heartbeat), §11 (trace)                         |
| 06 examples           | §13.1–§13.7 (one example per v1.1 surface, already worked out) |
| 07 tests              | §6.4–§6.6, §7.5–§7.6, §8.4, §9.5–§9.6, §12                     |
| 08 docs               | §1–§3, §6.2, §12                                               |
| 09 diagrams           | §6.4 ack/heartbeat, §7.6 subscribe FSM, §8.4 chunk sequence    |
