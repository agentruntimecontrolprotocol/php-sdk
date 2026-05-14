# 10 — Synthesis: ARCP PHP SDK v1.1 Migration

Inputs: `01-spec-delta.md` through `09-diagrams.md`. This file does
not restate them — it integrates, resolves contradictions, and orders
the work into PR-sized milestones with files + spec §.

---

## 1. Executive summary

The headline: **this is not a v1.1 patch, it is a v1.0 re-baseline
plus a v1.1 additive layer, in that order.** Phase 02 §1 surfaced
that the current `src/` implements `../spec/docs/draft-arcp-01.md`
(RFC-0001), not `draft-arcp-02.md` (v1.0). The wire taxonomy, the
error set, and the session/job lifecycle envelopes all diverge. v1.1
features (heartbeat, ack, list_jobs, subscribe, agent versioning,
lease expires_at, cost budget, progress, result_chunk) sit on top of
the v1.0 (draft-02) wire that this SDK does not yet speak.

Three structural moves drive every other decision:

1. **Re-baseline the wire to `draft-arcp-02.md` (v1.0).** This means
   renamed envelopes, a unified `job.event { kind, body }` shape, and
   the 12-code error taxonomy in `src/Errors/`. Phase 02 §6 enumerates
   the 9 items.
2. **Add the v1.1 surface.** Nine features behind a closed `Feature`
   enum, three new error codes, capability negotiation by intersection
   (Phase 01 §3). Per-feature client + runtime work, tested behind a
   conformance harness.
3. **Split the deployment story honestly.** `arcp/arcp` (core)
   becomes `symfony/console`-free and `firebase/php-jwt`-free; the CLI
   moves to `arcp/cli`, JWT auth to `arcp/auth-jwt`, and five host
   adapter packages land (`arcp/psr15`, `arcp/amphp-server`,
   `arcp/laravel`, `arcp/symfony-bundle`, `arcp/otel`). Phase 04 §1 and
   Phase 05 own the package layout; Phase 02 §7 and the deployment
   guide (Phase 08) call out daemon-not-`php-fpm` as a hard rule.

The plan reaches v1.1 in **six PR-sized milestones** (§5 below). The
first three are the v1.0 re-baseline; only milestones 4–6 add v1.1
surface. Coverage floor (87% line+branch via pcov, Phase 07 §6),
PHPStan max + strict-rules (kept; Psalm dropped, Phase 03 §13), and
`composer conformance` (Phase 07 §2.5) are the gates each milestone
clears.

---

## 2. Contradictions resolved

Resolved between phases so milestone work doesn't trip over conflicting
recommendations.

### 2.1. Where does `Arcp\Job\Event\Kind` live?

- Phase 01 §3.1 sketches `Feature` in `Arcp\Session`.
- Phase 04 §2 puts `Kind` in `Arcp\Job\Event`.
- Phase 02 §4.3 listed `Arcp\Job\Event\*` already.

**Resolution:** Phase 04 wins. `Kind` lives under `Arcp\Job\Event`
beside the body classes; `Feature` lives under `Arcp\Session`. The
two enums have no overlap.

### 2.2. `webmozart/assert` vs hand-rolled type guards in `fromArray`

- Phase 04 §2 said "Phase 03 chose between `webmozart/assert` and
  hand-rolled guards."
- Phase 03 §9 rejected `webmozart/assert` and chose hand-rolled
  guards that throw `InvalidRequestException`.

**Resolution:** Phase 03 wins. Every `fromArray()` decoder uses
`is_string($a['key']) ?? throw new InvalidRequestException(...)`
inline. Phase 04 §2 stands as written but reads as "hand-rolled."

### 2.3. Are there 9 PHP samples or all 18?

- Phase 06 §1 maps all 18 TS examples (9 v1.0 core + 9 v1.1).
- Phase 06 notes that the 9 v1.0 examples require the wire re-baseline
  to land first.

**Resolution:** The v1.0 samples land in milestone 3 (wire re-baseline)
under their final paths but with the v1.0-only feature subset; v1.1
samples land alongside their feature milestones (4, 5, 6). One sample
PR per milestone, not all 18 at once.

### 2.4. `bcmath` for cost-budget decrement?

- Phase 06 row `cost-budget` uses `bcsub` for currency math.
- Phase 03 §15 does not declare `ext-bcmath` in the ext-* list.

**Resolution:** Add `ext-bcmath` to `composer.json` under `suggest`
(runtime-only, only needed if `cost.budget` is in use), not `require`.
Document in the budgets guide (Phase 08 `guides/budgets.md`) that
consumers handling `cost.budget` should ensure `ext-bcmath` is
installed; the SDK falls back to a hand-rolled fixed-point decimal
if absent. Phase 06 row is updated implicitly.

### 2.5. PHP floor — 8.3 or 8.4?

- Phase 03 §0 (header) rolls back to `>=8.3`.
- Phase 02 §2 flagged `>=8.4` as accidentally tight.
- Phase 04 §4 uses `public const string CODE` (typed class constants —
  PHP 8.3 feature, lands on the floor).
- Phase 07 §5 tests on 8.3 + 8.4.

**Resolution:** Floor is **8.3**. `composer.json` change is part of
milestone 1.

### 2.6. Does `arcp/arcp` ship the OTEL middleware or is it `arcp/otel`?

- Phase 03 §8 names `open-telemetry/api ^1.0` in core.
- Phase 05 §5 puts the OTEL middleware (`TracingTransport`,
  `withTracing()`) in `arcp/otel`.

**Resolution:** Both. Core depends on `open-telemetry/api` because the
no-op tracer path (`Globals::tracerProvider()`) emits zero overhead
and Phase 04 §5 sketches `Arcp\Trace`. `arcp/otel` is the **decorator
transport** that wraps `Arcp\Transport\Transport` with a tracing layer
— it's an additive consumer choice. No conflict.

### 2.7. Does the SDK ship a custom PHPStan rule banning `sleep` in tests?

- Phase 07 §4 names a "custom PHPStan rule" banning
  `sleep|usleep|time_nanosleep`.

**Resolution:** Defer. Writing the rule is out of scope for v1.1; the
ban is enforced by code review and a `grep -r 'sleep\|usleep' tests/`
check in CI. Phase 07's claim is downgraded: PR description notes
"future custom rule," not "this milestone."

---

## 3. Risks

Five risks survive integration, ranked by likelihood × impact.

### 3.1. (H) v1.0 re-baseline is larger than the v1.1 features combined

Phase 02 §1 enumerates 11 wire-shape divergences. Phase 02 §4.2 lists
67 message classes; an estimated 30+ rename or merge in the
re-baseline. The risk is the milestone slips and v1.1 work starts
against a half-migrated base. Mitigation: milestone 1 lands the
envelope + serializer alone (no message-class renames yet); milestone
2 does the wire-shape rename in one large but mechanical PR; milestone
3 retires the gRPC-shaped exceptions in `src/Errors/` to the 15-code
set. Three landings, each independently testable against the
re-baselined `tests/Unit/fixtures/envelopes/`.

### 3.2. (H) Amp v3 fibers and `php-fpm` deployments cannot mix on the runtime side

Phase 02 §7 + Phase 08 `guides/deployment.md`. The runtime is a
daemon. Consumers who default to a `php-fpm` mental model will try to
mount `Arcp\Runtime\Server` inside a request handler, observe the
heartbeat loop dying when the request ends (Phase 04 §3.2 — the
`EventLoop::repeat()` registration evaporates with the worker
process), and file confusion bugs. Mitigation: the README quickstart
(Phase 08 §4) opens with the deployment model **before** the code
snippet, and the `arcp:serve` Artisan command (Phase 05 §3) hard-
checks `getenv('LARAVEL_OCTANE') === '1'` at boot.

### 3.3. (M) The `§9.6` budget-decrement race under cooperative fibers

Phase 04 §3.1 names the read-modify-write race; Phase 07 §3.8 tests
a 2× concurrent `tool.invoke` scenario. The risk is that the
suspension-hygiene rule is documentation, not enforcement — a code
reviewer can miss a `Future::await()` between the budget read and the
decrement, and the test for it (`§3.8` parallel variant) only
exercises one specific call site. Mitigation: budget enforcement lives
in a single method (`Arcp\Lease\CostBudgetCounter::tryDecrement()`)
that does read-check-decrement in straight-line PHP with **no method
calls that suspend** — every call inside is to PHP stdlib (`bcsub`,
`is_string`, array reads). Phase 04 §3.1 is amended: the rule is "no
method calls inside the critical section that the type system can't
prove are non-suspending."

### 3.4. (M) Drift between PHP wire types and TS wire types

Phase 07 §2.2 names a `MessageCatalogContractTest` that asserts every
`MessageType::cases()` value against a checked-in
`spec-message-types.json`. The risk is that the JSON drifts vs the TS
catalog and the SDKs disagree on the wire. Mitigation: the JSON is
generated from `../spec/docs/draft-arcp-02.1.md` by a one-time
extractor (a small `bin/extract-spec-messages.php` that parses the
§7 + §8 tables); CI runs it and fails on `git diff` — same drift check
Phase 09 uses for diagrams.

### 3.5. (M) Migration path for current v0.1 consumers

The current `README.md` advertises v0.1 with the RFC-0001 wire shape.
Anyone already integrated against `Arcp\Client\ARCPClient::invokeTool(...)`
(README quickstart) sees that surface deleted. Mitigation: Phase 08's
`MIGRATION-v1.1.md` is required for milestone 4 (the first v1.1
feature shipping); the `CHANGELOG.md` v1.1 entry calls v0.1 → v1.1 a
**breaking change**, not "additive," because of the v1.0 re-baseline
embedded in it. The `v1.0` PHP version is **not** published as a
separate Packagist tag — `arcp/arcp` jumps from `0.1.x` to `1.1.0`
in one move, and consumers track that hop via `MIGRATION-v1.1.md`.

---

## 4. Non-goals

Items explicitly out of scope for the v1.1 milestone. Listing them so
future-me doesn't unwittingly expand the plan.

- **Job pause / unpause** — spec "Not in v1.1" (Phase 01 §4). No
  `Feature::JobPause` case is added; `Arcp\Job\Job` carries no
  `pause()` method.
- **Job priority and scheduling hints** — same source.
- **Federation across runtimes** — same source.
- **LLM token streaming surface** — distinct from `result_chunk`
  (which is final-result streaming, §8.4). Out of v1.1.
- **Renewal of expired leases** — spec §9.5 says renewal is NOT
  supported in v1.1. The client must cancel and resubmit.
- **Custom PHPStan rule banning `sleep` in tests** — §2.7 above
  defers.
- **Swoole / OpenSwoole server adapter** — Phase 05 §6 rejected.
  Lives outside the v1.1 plan.
- **A PSR-18 implementation** — Phase 03 §4. Core ships interfaces
  only; consumer injects.
- **Pest migration** — Phase 03 §10. PHPUnit stays.
- **Eris property testing** — Phase 07 §1.
- **Symfony validator / webmozart/assert** — Phase 03 §9. Hand-rolled
  guards win.
- **JSON Schema runtime validation** — Phase 03 §9 + Phase 02 §2.
  `justinrainbow/json-schema` is removed.
- **`Arcp\Core\` namespace prefix** — Phase 04 §1.1. Flat namespaces
  win.
- **Per-language-version coverage matrix** — Phase 07 §5. One cell.
- **Light/dark diagram split** — Phase 09 §0. Single variant for v1.1.

---

## 5. Milestones (PR-sized, ordered)

Each milestone is one PR. Files listed are what changes; spec §
cites identify the contract under test.

### Milestone 1 — Envelope + serializer re-baseline

**Goal:** the envelope speaks v1.0 wire (draft-02 §5) before any
message class is touched.

| What                                                        | Where                                                                                                | Spec §          |
| ----------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- | --------------- |
| Add `arcp: "1"` literal version field                       | `src/Envelope/Envelope.php`, `src/Envelope/EnvelopeSerializer.php` (move from `src/Json/`)            | §5.1            |
| Reject unknown `arcp` values; ignore unknown top-level keys | `Envelope::fromArray`                                                                                | §5.1            |
| W3C 32-hex `trace_id` validation                            | `src/Envelope/Envelope.php`, `src/Trace/`                                                            | §5.1, §11       |
| Drop `ext-json`, drop `ext-pdo*` from core require           | `composer.json`                                                                                      | (Phase 03 §15)  |
| PHP floor `>=8.3`                                           | `composer.json`                                                                                      | (Phase 03 §0)   |
| Drop `justinrainbow/json-schema`                             | `composer.json`                                                                                      | (Phase 03 §9)   |
| Drop `vimeo/psalm`                                           | `composer.json`, delete `psalm.xml`                                                                  | (Phase 03 §13)  |
| Regenerate envelope fixtures                                 | `tests/Unit/fixtures/envelopes/13.*.json`                                                            | §13             |
| `EnvelopeTest` updated to assert §5.1 shape                  | `tests/Unit/Envelope/EnvelopeTest.php`                                                              | §5.1            |

**Gate:** `composer gates` (`lint → stan → test`), coverage ≥87% on
the touched files. No v1.1 features touched yet.

### Milestone 2 — Message-class rename to v1.0 wire shape

**Goal:** the 67 existing message classes (Phase 02 §4.2) become the
v1.0 set.

| What                                                                                          | Where                                            | Spec §        |
| --------------------------------------------------------------------------------------------- | ------------------------------------------------ | ------------- |
| `session.open/accepted/authenticate/challenge/rejected` → `session.hello/welcome/error`        | `src/Messages/Session/` → `src/Session/`         | §6.1, §6.2    |
| `session.close/evicted` → `session.bye`                                                       | `src/Session/Bye.php`                            | §6.7          |
| `job.started/completed/failed/cancelled` → `job.submit/accepted/event/result/error`            | `src/Messages/Execution/` → `src/Job/`           | §7.1, §7.3    |
| Unify `log`, `metric`, `event.emit`, `trace.span`, `tool.invoke/result/error`, `stream.*` into `job.event { kind, body }` with `Arcp\Job\Event\Kind` enum | `src/Job/Event/`                                 | §8.1, §8.2   |
| `cancel/cancel.accepted/cancel.refused` → `job.cancel`                                        | `src/Job/Cancel.php`                             | §7.4          |
| `subscribe/subscribe.event/subscribe.accepted/subscribe.closed/unsubscribe` deferred to milestone 5 (v1.1 subscribe is per-job, not generic) | `src/Job/Subscribe.php` (placeholder)            | §7.6          |
| Move `Arcp\Json\EnvelopeSerializer` → `Arcp\Envelope\EnvelopeSerializer`                       | `src/Envelope/`                                  | (Phase 04)    |
| Delete RFC-0001-only classes: `agent.handoff`, `workflow.start/complete`, `permission.*`, `lease.refresh/extended`, `checkpoint.*`, `human.*`, `artifact.*` (the last group revisits as event `kind`s in milestone 6) | `src/Messages/{Human,Permissions,Artifacts}/`    | (Phase 02 §6) |
| Regenerate `tests/Unit/Messages/*Test.php` for renamed classes                                | `tests/Unit/Messages/`                           | §6–§8         |
| `MessageCatalogContractTest` against `spec-message-types.json`                                | `tests/Unit/Envelope/`, `bin/extract-spec-messages.php` | §6, §7, §8    |

**Gate:** integration suite (`tests/Integration/`) passes against
`MemoryTransport::pair()`; WS loopback (`amphp/websocket-server`)
passes the handshake → submit → event → cancel scenario.

### Milestone 3 — Error taxonomy + handshake mechanics

**Goal:** the 21 gRPC-named exceptions in `src/Errors/` collapse to 12
v1.0 canonical codes, and the `session.hello`/`welcome`/`bye`
handshake works end-to-end.

| What                                                                                       | Where                                            | Spec §  |
| ------------------------------------------------------------------------------------------ | ------------------------------------------------ | ------- |
| Delete `AbortedException`, `DataLossException`, `FailedPreconditionException`, `UnavailableException`, `UnimplementedException`, `OutOfRangeException`, `ResourceExhaustedException`, `AlreadyExistsException`, `DeadlineExceededException`, `BackpressureOverflowException`, `LeaseRevokedException` | `src/Errors/`                                    | §12     |
| Keep + rename to spec names: `PERMISSION_DENIED`, `LEASE_SUBSET_VIOLATION`, `JOB_NOT_FOUND`, `DUPLICATE_KEY`, `AGENT_NOT_AVAILABLE`, `CANCELLED`, `TIMEOUT`, `RESUME_WINDOW_EXPIRED`, `HEARTBEAT_LOST`, `INVALID_REQUEST`, `UNAUTHENTICATED`, `INTERNAL_ERROR` | `src/Errors/`                                    | §12     |
| Each exception is `final`, `public const string CODE`, overrides `getCode(): string`        | `src/Errors/*Exception.php`                      | §12     |
| `ErrorCode` enum retightens to 12 cases (v1.0); add the 3 v1.1 cases in milestone 4         | `src/Errors/ErrorCode.php`                       | §12     |
| `session.hello`/`welcome` round-trip integration test                                       | `tests/Integration/HandshakeTest.php`            | §6.2    |
| `session.bye` close test                                                                    | `tests/Integration/CloseTest.php`                | §6.7    |
| Resume token rotation on every welcome                                                      | `src/Runtime/Server.php` + test                  | §6.3    |
| Move `Arcp\Cli` → `arcp/cli` side package (one-PR repo split, or marker for follow-up)     | `arcp/cli` repo                                  | (Phase 04 §1) |
| Move `Arcp\Auth\JwtAuth` → `arcp/auth-jwt` side package                                     | `arcp/auth-jwt` repo                             | (Phase 04 §1) |
| Drop `firebase/php-jwt` from core `composer.json`; drop `symfony/console`                  | `composer.json`                                  | (Phase 04 §1) |
| Rewrite `CONFORMANCE.md` to the TS shape (407-line section-by-section matrix), v1.0-only column populated | `CONFORMANCE.md`                                 | §4–§16  |

**Gate:** v1.0 conformance harness (`composer conformance`) passes
every §4–§12 row. README quickstart updated to v1.0 API (Phase 08 §4
shape), v0.1 deprecation note added.

**Milestones 1–3 are the v1.0 re-baseline. Below is v1.1 surface.**

### Milestone 4 — Capability negotiation + heartbeat + ack

**Goal:** the foundation v1.1 features that everything else negotiates
against.

| What                                                                                         | Where                                            | Spec §       |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------ | ------------ |
| `enum Feature: string` (9 cases)                                                             | `src/Session/Feature.php`                        | §6.2         |
| `CapabilitySet` value object with `intersect()` and `supports()`                              | `src/Session/CapabilitySet.php`                  | §6.2         |
| `AgentInventory` + `AgentEntry` with `fromFlat()` v1.0 compat path                            | `src/Session/AgentInventory.php`                 | §6.2, §7.5   |
| Hello/welcome carry `features` and rich `agents` shape                                       | `src/Session/Hello.php`, `Welcome.php`           | §6.2         |
| `session.ping` / `session.pong`                                                              | `src/Session/Ping.php`, `Pong.php`               | §6.4         |
| `HeartbeatLoop` via `Revolt\EventLoop::repeat()`, 2× silence → `HeartbeatLostException`       | `src/Session/HeartbeatLoop.php`                  | §6.4         |
| `session.ack { last_processed_seq }`; `EventLog::evictUpTo($seq)`                            | `src/Session/Ack.php`, `src/Runtime/EventLog.php` | §6.5        |
| `UnnegotiatedFeatureException` (library-internal, not a wire code)                           | `src/Errors/`                                    | (Phase 01 §3.4) |
| `CapabilityNegotiationTest`, `HeartbeatTest`, `AckTest`                                      | `tests/Integration/V11/`                         | §6.2–§6.5    |
| Samples: `samples/heartbeat/`, `samples/ack-backpressure/`                                   | `samples/`                                       | (Phase 06)   |
| Diagrams: `capability-negotiation.dot`, `heartbeat-flow.dot`, `ack-flow.dot`                 | `docs/diagrams/`                                 | (Phase 09)   |
| `docs/concepts/heartbeats.md`, `docs/reference/capabilities.md`                              | `docs/`                                          | §6.2, §6.4   |
| `MIGRATION-v1.1.md` first cut                                                                 | `docs/MIGRATION-v1.1.md`                         | (Phase 08 §6) |

**Gate:** Phase 07 §3.1, §3.2, §3.9 tests pass. `composer conformance`
adds rows for §6.2, §6.4, §6.5; all pass.

### Milestone 5 — List jobs + subscribe + agent versioning

**Goal:** the cross-session observation surface.

| What                                                                                         | Where                                            | Spec §       |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------ | ------------ |
| `session.list_jobs` / `session.jobs` with cursor                                              | `src/Session/ListJobs.php`, `JobsResponse.php`   | §6.6         |
| Per-principal visibility enforcement in `JobManager::list()`                                 | `src/Runtime/JobManager.php`                     | §6.6         |
| `job.subscribe` / `job.subscribed` / `job.unsubscribe`                                       | `src/Job/Subscribe.php`, `Subscribed.php`, `Unsubscribe.php` | §7.6 |
| `SubscriptionManager` reworked: principal-scoped, history replay via `from_event_seq`         | `src/Runtime/SubscriptionManager.php`            | §7.6         |
| Subscriber cannot cancel (client-side block + runtime-side `PERMISSION_DENIED` on raw envelope) | `src/Client/JobHandle.php`, `src/Runtime/JobManager.php` | §7.6 |
| `name@version` parsing via `AgentRef::parse()`                                                | `src/Job/AgentRef.php`                           | §7.5         |
| `AgentVersionNotAvailableException`                                                          | `src/Errors/`                                    | §12          |
| `ListJobsTest`, `SubscribeTest`, `AgentVersionsTest`                                          | `tests/Integration/V11/`                         | §6.6, §7.5, §7.6 |
| Samples: `samples/list-jobs/`, `samples/subscribe/`, `samples/agent-versions/`                | `samples/`                                       | (Phase 06)   |
| Diagrams: extended `job-fsm.dot` with subscribe-observer states                              | `docs/diagrams/`                                 | (Phase 09)   |
| `docs/concepts/subscribe.md`, `docs/guides/agent-versioning.md`                              | `docs/`                                          | §6.6, §7.5, §7.6 |

**Gate:** Phase 07 §3.3, §3.4, §3.5 pass; cross-principal isolation
test asserts exact `job_id` set (no leakage).

### Milestone 6 — Lease expiration + budget + progress + result_chunk

**Goal:** the v1.1 finish line — authority bounds and large-result
streaming.

| What                                                                                         | Where                                            | Spec §       |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------ | ------------ |
| `LeaseConstraints` (ISO-8601 UTC `Z` only)                                                   | `src/Lease/LeaseConstraints.php`                 | §9.5         |
| `LeaseManager::evaluate()` checks `expires_at` on every authority op                          | `src/Runtime/LeaseManager.php`                   | §9.5         |
| `LeaseExpiredException` (retryable: false)                                                   | `src/Errors/`                                    | §12          |
| `CostBudget` capability parser (`CCY:amount` grammar) + counters                             | `src/Lease/CostBudget.php`, `CostBudgetCounter.php` | §9.6      |
| Decrement on `metric { name: cost.*, unit: <ccy>, value: ... }`; `bcsub` for currency math    | `src/Runtime/LeaseManager.php`                   | §9.6         |
| `BudgetExhaustedException` (retryable: false)                                                | `src/Errors/`                                    | §12          |
| Suspension-hygiene contract: `CostBudgetCounter::tryDecrement()` is straight-line PHP, no method calls that can suspend | `src/Lease/CostBudgetCounter.php`                | (Phase 04 §3.1, risk §3.3) |
| Add `ext-bcmath` to `suggest` (runtime-only, only `cost.budget` consumers need it)            | `composer.json`                                  | (§2.4)       |
| `progress` body — `Arcp\Job\Event\ProgressBody`                                              | `src/Job/Event/ProgressBody.php`                 | §8.2.1       |
| `result_chunk` body — `Arcp\Job\Event\ResultChunkBody`                                       | `src/Job/Event/ResultChunkBody.php`              | §8.4         |
| `$ctx->streamResult()` returns `Pipeline<ResultChunkBody>`; terminating `job.result.result_id` | `src/Runtime/JobContext.php`                     | §8.4         |
| Reject inline + chunked mix per §8.4                                                          | `src/Runtime/JobManager.php`                     | §8.4         |
| Trace attrs: `arcp.lease.expires_at`, `arcp.budget.remaining`                                 | `src/Trace/SpanAttributes.php`                   | §11          |
| `LeaseExpiresAtTest`, `CostBudgetTest`, `ResultChunkTest`, `progress` sample test            | `tests/Integration/V11/`                         | §8.4, §9.5, §9.6 |
| Samples: `samples/lease-expires-at/`, `samples/cost-budget/`, `samples/progress/`, `samples/result-chunk/` | `samples/`                                       | (Phase 06)   |
| Diagrams: `result-chunk-sequence.dot`, `progress-events.dot`; `job-fsm.dot` final v1.1 form  | `docs/diagrams/`                                 | (Phase 09)   |
| `docs/concepts/leases.md` updated; `docs/guides/{budgets,result-streaming}.md`                | `docs/`                                          | §8.4, §9.5, §9.6 |
| Side packages tagged 1.1.0: `arcp/psr15`, `arcp/amphp-server`, `arcp/laravel`, `arcp/symfony-bundle`, `arcp/otel`, `arcp/cli`, `arcp/auth-jwt` | (separate repos)                                | (Phase 05)   |
| `CHANGELOG.md` v1.1 entry; `MIGRATION-v1.1.md` final                                         | `CHANGELOG.md`, `docs/MIGRATION-v1.1.md`        | (Phase 08)   |
| Infection MSI ≥ 70% on `src/Envelope`, `src/Session`, `src/Job`, `src/Lease` (nightly cron)  | `.github/workflows/nightly-mutation.yml`         | (Phase 07 §1) |
| Tag `arcp/arcp` `1.1.0`                                                                       | git tag                                          | —            |

**Gate:** `composer conformance` reports every §4–§12 row passing for
v1.0 + v1.1. README + `MIGRATION-v1.1.md` ship. All 18 samples
runnable via `php samples/<name>/run.php` exiting 0.

---

## 6. Cross-cutting deliverables

These touch every milestone, not one specific one:

- **CI matrix** (Phase 07 §5): PHP 8.3 base, PHP 8.4 with coverage
  + 87% line+branch gate, PHP 8.4 nightly mutation. Composer
  `--prefer-lowest` cell on 8.3.
- **Static analysis** (Phase 02 §3, Phase 03 §13): PHPStan max +
  `phpstan-strict-rules` continues at level max with the existing
  `checkUninitializedProperties`, `reportAnyTypeWideningInVarTag`,
  etc. No regression allowed in any milestone.
- **`composer.json` scripts** (Phase 02 §2): keep `gates`, add
  `conformance` (milestone 3 onward), add `api-docs` (milestone 6)
  invoking phpDocumentor, add `diagrams` (milestone 4 onward)
  invoking `bin/render-diagrams.sh`.
- **`CONFORMANCE.md`** rewritten in milestone 3 (v1.0 column) and
  extended per-milestone (4: §6.2, §6.4, §6.5; 5: §6.6, §7.5, §7.6;
  6: §8.4, §9.5, §9.6, §11, §12 new codes).
- **Anti-slop hygiene** (every Phase brief): banned filler in commit
  messages, PR descriptions, and docs. The README quickstart must
  run as-is — code blocks tested via `composer docs-test` (Phase 08).

---

## 7. Open questions

Items not resolved by the nine phase files; each needs a decision
**before** the milestone that depends on it lands.

1. **Single repo or many for the side packages?** Phase 04 §1 says
   each side package gets its own repo, but the workspace memory notes
   `php-sdk` is one repo. Is `arcp/cli`, `arcp/auth-jwt`, etc., a new
   `*-sdk`-adjacent repo, or a subtree split? Affects how milestone 3
   ships the CLI/JWT extraction. Default: **separate repos** unless
   the workspace owner overrides.

2. **Does `arcp/arcp` 1.1.0 ship the OTEL trace context extension key
   `x-vendor.opentelemetry.tracecontext` baked into the envelope
   spec, or as a vendor extension only?** Phase 05 §5 reads the TS
   source as treating it as a vendor extension. Spec §15 governs
   extension namespace conventions; verify before milestone 6 wiring.

3. **`session.ack`'s wire shape — does it carry a `seq` for the ack
   message itself?** Spec §6.5 says "`session.ack` messages are not
   included in `event_seq`," but envelope `id` (§5.1) is still
   required. Decide whether `id` for `session.ack` is treated like a
   regular envelope `id` (ULID/UUIDv7) or fixed (`"ack"`). Default:
   regular envelope `id` per §5.1 — every envelope gets one.

4. **The exact phpDocumentor version pin.** Phase 08 names
   `phpdocumentor/shim`; verify the latest stable is ≥3.5 and supports
   PHP 8.3 + 8.4. If it lags, fallback is hand-rolled API extraction
   from `ReflectionClass` — much narrower scope, Phase 08 §3 needs
   amending.

5. **Is there a Linear / GitHub-issue tracker for these milestones, or
   are they tracked here only?** Doesn't block work but determines
   whether the milestones become issues or just PR descriptions.

Resolve via the workspace owner (`nficano@gmail.com`) before
milestone 1 lands. Open questions 1, 2, 3 are technical decisions; 4
is a tooling availability check; 5 is a process question.

---

## 8. Reading order for an incoming contributor

If a new contributor lands here cold:

1. `BOOTSTRAP.md` (in this dir) — the brief.
2. `01-spec-delta.md` — what v1.1 adds.
3. `02-current-audit.md` — what the SDK is now (and why §1 is bigger
   than it looks).
4. `04-architecture.md` — the target shape.
5. **This file** — milestone order.
6. The phase file for whichever milestone they're picking up
   (Phase 03 for deps, 05 for adapters, 06 for samples, 07 for
   tests, 08 for docs, 09 for diagrams).

Phase 02 §6 (the v1.0 re-baseline list) is the single most load-
bearing section in the plan — read it twice.
