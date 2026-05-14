# 06 — Examples

Maps the 18 canonical TypeScript examples
(`../typescript-sdk/examples/README.md` — the v1.0 core block of 9
plus the v1.1 features block of 9) onto a new `samples/` tree under
this SDK. The four host-integration examples (`tracing`, `express`,
`fastify`, `bun`) are out of scope here: they exercise framework
adapters and live with the middleware packages planned in
`05-middleware.md` (PSR-15, Amp-WS server, Laravel, Symfony,
`arcp/otel`). The current `samples/` tree (`subscriptions`,
`leases`, `lease_revocation`, `permission_challenge`, `delegation`,
`handoff`, `heartbeats`, `capability_negotiation`, `resumability`,
`reasoning_streams`, `extensions`, `human_input`, `cancellation`,
`mcp`) is retired wholesale — see §5.

## 1. Mapping table

Two files per directory: `server.php` (runtime side) and
`client.php` (consumer side). A third file, `run.php`, is the entry
point CI invokes (§2). The TS example path is `../typescript-sdk/examples/<name>/`
in every row; spec § references are to `../spec/docs/draft-arcp-02.1.md`.

| TS name              | PHP sample path                | Files                                  | Spec §                | PHP idiom shown                                                                                                                                                                                                              |
| -------------------- | ------------------------------ | -------------------------------------- | --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `submit-and-stream`  | `samples/submit-and-stream/`   | `server.php`, `client.php`, `run.php`  | §13.1, §7.1, §8.2     | `Arcp\Client::submit(...)` returns `JobHandle`; `foreach ($handle->events() as Arcp\Job\Event\JobEvent $e) match ($e->kind) { ... }` — exhaustive `match` over the seven reserved `Kind` enum cases is the dispatch idiom. |
| `delegate`           | `samples/delegate/`            | `server.php`, `client.php`, `run.php`  | §13.2, §10            | Parent agent emits `delegate` body; child runs under a `LeaseSubsetting::bound($parent)` snapshot — the snapshot is taken inside a no-suspension block per `02-current-audit.md` §5 row §9.4. `trace_id` propagated as readonly W3C 32-hex.|
| `resume`             | `samples/resume/`              | `server.php`, `client.php`, `run.php`  | §13.3, §6.3           | Client closes the `Amp\Websocket\Client\WebsocketConnection`; reconnects with `Hello { resume_token, last_event_seq }`; `EventLog::replay($from)` yields a `Generator<JobEvent>`. Token rotation asserted via `assertNotSame()`-style check in `run.php`. |
| `idempotent-retry`   | `samples/idempotent-retry/`    | `server.php`, `client.php`, `run.php`  | §13.5, §7.2           | Same `(principal, idempotency_key)` returns the same `job_id` (asserted via PHP `===` on the returned ID string). Different `agent` argument throws `Arcp\Errors\DuplicateKeyException` — caught with `try`/`catch` typed on the spec class.|
| `lease-violation`    | `samples/lease-violation/`     | `server.php`, `client.php`, `run.php`  | §13.4, §9.3           | Agent calls an out-of-lease tool; runtime surfaces `tool_result.error.code = "PERMISSION_DENIED"` inside a `job.event` body. The agent's PHP code uses a `try`/`catch` on `Arcp\Errors\PermissionDeniedException`, logs, and continues — job ends `success`. |
| `cancel`             | `samples/cancel/`              | `server.php`, `client.php`, `run.php`  | §7.4                  | Client builds `DeferredCancellation`, schedules `Revolt\EventLoop::delay(0.2, fn() => $deferred->cancel())`; the agent's `run(Arcp\Agent\Ctx $ctx, Cancellation $cancel)` is suspended at an `Amp\delay(..., $cancel)` and throws `CancelledException`. Runtime emits `job.error { final_status: "cancelled" }`. **No `usleep`.** |
| `stdio`              | `samples/stdio/`               | `server.php`, `client.php`, `run.php`  | §4.2                  | `Arcp\Transport\StdioTransport` over `amphp/process`; `run.php` spawns `php server.php` as a child via `Amp\Process\Process::start()`; the parent process owns the `Amp\ByteStream\ReadableResourceStream` pair. Single-process invocation per TS parity. |
| `vendor-extensions`  | `samples/vendor-extensions/`   | `server.php`, `client.php`, `run.php`  | §8.2, §9.2, §15       | Agent emits `kind: "x-vendor.acme.progress"`; the closed `Arcp\Job\Event\Kind` enum drops the unknown case at decode (`01-spec-delta.md` §3.1 — same closed-enum rule). The vendor-aware client registers an `ExtensionHandler` via `Arcp\Extensions\ExtensionRegistry::register('x-vendor.acme', ...)`; naive client uses the default handler that re-emits the raw body. |
| `custom-auth`        | `samples/custom-auth/`         | `server.php`, `client.php`, `run.php`  | §6.1                  | Implements `Arcp\Auth\BearerVerifier` (interface, not a class hierarchy) with HMAC-signed token via stdlib `hash_hmac('sha256', ...)`. Bad tokens fail at handshake with `Arcp\Errors\UnauthenticatedException`. The signer lives in a tiny `signer.php` module; `run.php` asserts the failure path with a bad token first. |
| `heartbeat`          | `samples/heartbeat/`           | `server.php`, `client.php`, `run.php`  | §6.4                  | Negotiates `Feature::Heartbeat`; runtime publishes `welcome.heartbeat_interval_sec = 1`. `run.php` advances a `Arcp\Clock\FakeClock` (already in `src/Clock/`) by 2× interval while suppressing pong responses on the client side — the runtime then closes the transport and `client.php`'s `JobHandle` surfaces `HeartbeatLostException`. The 2×-silence trigger is mechanical, not wall-clock — no `sleep`. |
| `ack-backpressure`   | `samples/ack-backpressure/`    | `server.php`, `client.php`, `run.php`  | §6.5, §8.2            | Client deliberately throttles its `JobEvent` `Pipeline` consumption (suspends between events); runtime accumulates buffer past `last_processed_seq` lag threshold and emits `status { phase: "back_pressure" }`. After the client drains and acks, `EventLog::evictUpTo($seq)` (existing `src/Store/EventLog.php`) frees buffer. |
| `list-jobs`          | `samples/list-jobs/`           | `server.php`, `client.php`, `run.php`  | §6.6                  | `Arcp\Client::listJobs(new ListJobsFilter(status: [JobStatus::Running]))` returns `Generator<JobSummary>` paginated on `next_cursor`; cancellation token threaded through so closing the iterator early stops the SQLite cursor walk in `EventLog` (audit §5 risk note for §6.6). |
| `subscribe`          | `samples/subscribe/`           | `server.php`, `client.php`, `run.php`  | §7.6, §6.6            | Two `Arcp\Client` instances on the same `principal`. Client B does `client->subscribeJob($jobId, history: true, fromEventSeq: 0)` → `Pipeline<JobEvent>` with **no cancel authority**; calling `$handleB->cancel()` throws `PermissionDeniedException`. Replayed events come first, then live. |
| `agent-versions`     | `samples/agent-versions/`      | `server.php`, `client.php`, `run.php`  | §7.5                  | Server registers two versions of `code-refactor`; client reads `AgentInventory` off `Session::$capabilities->agents`. Submitting `code-refactor@9.9.9` throws `AgentVersionNotAvailableException` (`01-spec-delta.md` §2). The agent-spec string is parsed by a small `AgentRef::parse('name@version'): AgentRef` value object using `str_contains` + explode — no regex. |
| `lease-expires-at`   | `samples/lease-expires-at/`    | `server.php`, `client.php`, `run.php`  | §9.5                  | Submits with `lease_constraints: new LeaseConstraints(expiresAt: $now->modify('+200ms'))`; `run.php` advances `FakeClock` past the deadline. Agent's next tool call surfaces `LeaseExpiredException`; runtime emits `job.error { code: "LEASE_EXPIRED" }`. ISO-8601 with `Z` only is enforced by `LeaseConstraints::__construct` (audit §5 risk note for §9.5). |
| `cost-budget`        | `samples/cost-budget/`         | `server.php`, `client.php`, `run.php`  | §9.6                  | Submits `lease_request.cost.budget = ["USD:1.00"]`. Agent emits `metric { name: "cost.search", value: "0.42", unit: "USD" }`; `CostBudgetCounter::decrement('USD', '0.42')` uses `bcsub` to avoid float drift on currency. Runtime emits debounced `cost.budget.remaining`; third call hits `BudgetExhaustedException` surfaced as a `tool_result.error`. |
| `progress`           | `samples/progress/`            | `server.php`, `client.php`, `run.php`  | §8.2.1                | Agent emits `kind: "progress" { current: $i, total: 100, units: "files" }` in a `foreach` over a fixture array. Client receives typed `ProgressBody` (`final readonly class`) and renders a one-line text bar using `str_repeat('#', $pct / 4) . str_repeat('-', 25 - $pct/4)`. Progress is advisory only — the wire takes no action. |
| `result-chunk`       | `samples/result-chunk/`        | `server.php`, `client.php`, `run.php`  | §8.4                  | Agent uses `$ctx->streamResult()` to emit ~30 `result_chunk` bodies (`Amp\Pipeline\Pipeline<ResultChunkBody>`); terminal `job.result` carries `result_id` and `result_size`. Client calls `$handle->collectChunks(): Generator<string>` which decodes per `encoding` (utf8 passes through; `base64` via `base64_decode($s, strict: true)`) and asserts `chunk_seq` monotonicity. |

Notes:

- The 9 v1.0 examples (`submit-and-stream` through `custom-auth`)
  are listed for completeness; they require the v1.0 wire re-
  baseline called out in `02-current-audit.md` §6 to land before
  they can run. Phase 10 sequences that work.
- `stdio` is the only sample that does **not** use
  `MemoryTransport::pair()` — its whole point is the subprocess
  pipe (§2).
- `vendor-extensions` is the one example where PHP cannot mirror TS
  verbatim: the TS demo type-checks `unknown` event kinds via
  TypeScript's structural typing. PHP's closed `Kind` enum (per
  `01-spec-delta.md` §3.1) drops unknown kinds at decode; the
  vendor-aware path goes through `ExtensionRegistry`, not through
  enum extension. The sample still demonstrates the spec behaviour
  (§8.2 — unknown kinds are advisory, runtime takes no action), it
  just demonstrates it through PHP's idiom.

## 2. Runner shape

`php samples/<name>/run.php` is the single CI entry point per
sample. Protocol:

1. Exit code `0` on success; any non-zero on assertion failure or
   uncaught exception. `run.php` wraps the body in
   `_harness.php`'s `runOrExit(callable $fn): never` (§3).
2. Transport defaults to `Arcp\Transport\MemoryTransport::pair()`
   — server and client share a process and an
   `Revolt\EventLoop` instance, no socket. Three exceptions:
   `stdio/` uses `StdioTransport` over an `amphp/process` child;
   `heartbeat/` uses `MemoryTransport` with a `FakeClock` injected
   on both sides so the 2×-interval trigger is mechanical;
   `cancel/` uses `MemoryTransport` and schedules cancel via
   `Revolt\EventLoop::delay(0.2, fn () => $cancellation->cancel())`
   — never `usleep` or `sleep` (rule from `02-current-audit.md` §7:
   the runtime is a daemon, but the samples are also short-lived
   and must not depend on wall-clock waits).
3. Each sample prints **one** terse human-readable summary line to
   stderr followed by a single JSON-encoded line of outcomes to
   stdout. The CI assertion contract is the JSON line, consumed via
   `jq` — the human line is purely operator ergonomics. Shape:
   `{"sample":"<name>","ok":true,"asserts":{"<key>":<value>,...}}`.
4. No `print_r` / `var_dump`. Logger is PSR-3 (`Arcp\Samples\Harness\StderrLogger`)
   per `03-libraries.md` (the user provides a logger; the samples
   provide a tiny stderr-line PSR-3 impl so the samples themselves
   do not pull Monolog).
5. Cancellation samples (`cancel/`, `subscribe/`, `lease-expires-at/`)
   use `Amp\DeferredCancellation` + `Revolt\EventLoop::delay()`
   exclusively. The harness fails the run if `$_ENV['ARCP_SAMPLE_TIMEOUT_SEC']`
   (default 5s) elapses, killing the loop via
   `EventLoop::cancel($watcher)` plus a top-level
   `DeferredCancellation` passed into both `client.php` and
   `server.php` entry points.

## 3. Common harness (`samples/_harness.php`)

A single file pulled in via `require_once __DIR__ . '/../_harness.php';`
from every `run.php`. Contents (described — not implemented in this
plan):

- `runOrExit(callable $fn): never` — `Amp\Future`-aware wrapper
  that runs `$fn` inside `Revolt\EventLoop::run()`, catches every
  `Throwable`, writes a one-line failure summary plus
  `{"ok":false,"error":{"class":..., "message":..., "code":...}}`
  JSON, and `exit(1)`s. Success path writes the success JSON line
  and `exit(0)`s.
- `StderrLogger` — 40-line `Psr\Log\LoggerInterface` impl writing
  `[level] message {context}` lines to `STDERR`. No buffering, no
  rotation. The default for `Arcp\Client`/`Arcp\Runtime` whenever a
  sample doesn't override.
- `emit(string $sample, array $asserts): void` — writes the
  contractual JSON line to `STDOUT`, single line, via
  `json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)`.
  CI invokes `jq '.asserts'` against this.
- `withTimeout(float $seconds, callable $fn, ?Cancellation $parent = null): mixed`
  — composes a child `Amp\DeferredCancellation` linked to `$parent`,
  schedules `EventLoop::delay($seconds, fn () => $deferred->cancel())`,
  hands the `Cancellation` to `$fn`, cancels the delay on success.
  Used by every sample that interacts with a network or a fake
  clock.
- `pairMemory(): array{0: Arcp\Transport\Transport, 1: Arcp\Transport\Transport}`
  — wraps `MemoryTransport::pair()` for the common case.
- `fakeClock(string $iso): Arcp\Clock\FakeClock` — convenience
  factory; the type already exists in `src/Clock/`.

The harness pulls **no** Composer deps not already in
`composer.json`'s runtime block — `amphp/amp`, `revolt/event-loop`,
`psr/log` (declared interface), and the in-tree `Arcp\` namespace.
That keeps the samples runnable without `composer install --dev`.

## 4. Per-example highlights for v1.1 surfaces

One paragraph each, anchored to the spec section it teaches. These
are the rows whose content is load-bearing on v1.1 behaviour — the
rest of the table above is enough for the v1.0-core nine.

- **`progress`** (§8.2.1). Agent emits `kind: "progress"` bodies
  inside `job.event`; client decodes them into `Arcp\Job\Event\ProgressBody`
  (`final readonly`) and renders a running total. The sample
  asserts the body type (`instanceof ProgressBody`) on every
  decoded event, asserts monotonic `current`, and asserts the
  protocol takes no action — `progress` is advisory per the spec
  table (`01-spec-delta.md` §1 row §8.2.1).

- **`result-chunk`** (§8.4). Agent calls `$ctx->streamResult()`,
  which returns an `Amp\Pipeline\Queue` it writes `ResultChunkBody`
  instances into. The runtime stamps each body with a monotonic
  `chunk_seq` and the same `result_id`. The terminating envelope
  is `job.result { result_id, result_size }` — **not** another
  `result_chunk` (§8.4 forbids mixing inline `result` with
  chunked results in the same job). Client's
  `$handle->collectChunks(): Generator<string>` decodes per
  `encoding` (`utf8` passes through; `base64` via stdlib
  `base64_decode($s, strict: true)`) and asserts contiguous
  `chunk_seq` 0..N. The reassembled blob's `strlen` matches
  `result_size`.

- **`subscribe`** (§7.6). Two `Arcp\Client` instances share a
  principal. Client A submits a job; Client B calls `listJobs()`
  then `subscribeJob($jobId, history: true, fromEventSeq: 0)`. B's
  handle is a `JobSubscription` value object exposing
  `events(): Pipeline<JobEvent>` but **not** `cancel()` — the
  type system enforces no cross-session cancel, not a runtime
  check. The sample also asserts that B's transport drop does not
  affect A's submission (§7.6 — subscription is observer-only).
  `from_event_seq` replay is implemented by walking
  `EventLog::replay($from)` (already in `src/Store/EventLog.php`)
  and then handing off to the live `Pipeline`.

- **`list-jobs`** (§6.6). Cursor pagination via
  `Arcp\Session\ListJobsRequest { filter, cursor?, limit? }` →
  `JobsResponse { jobs: list<JobSummary>, next_cursor: ?string }`.
  Client wraps that in `Arcp\Client::listJobs(): Generator<JobSummary>`
  which auto-pages on `next_cursor` and threads cancellation
  through — closing the generator (`$gen->return()`) propagates
  cancel to the SQLite cursor walk (audit §5 risk note for §6.6).
  Filter shown: `status: [JobStatus::Running]`.

- **`heartbeat`** (§6.4). Both sides negotiate `Feature::Heartbeat`
  with `welcome.heartbeat_interval_sec = 1`. The client deliberately
  installs a no-op pong handler; the runtime's heartbeat watchdog
  (an `EventLoop::repeat(1, ...)` watcher) sends `ping`, waits the
  interval, sends a second `ping`, then closes the transport. The
  sample asserts the close is observed as
  `Arcp\Errors\HeartbeatLostException` on the client side. Time is
  driven by `Arcp\Clock\FakeClock` advanced inside an
  `EventLoop::queue(...)` so the run completes in milliseconds of
  wall-clock.

- **`ack-backpressure`** (§6.5). Client throttles its `Pipeline`
  consumption with a one-event-per-iteration `Amp\delay(0.005)`.
  Runtime hits its lag threshold (200 events per
  `01-spec-delta.md` §1 row §6.5 — make threshold a
  `Arcp\Runtime\Config::$backPressureLagThreshold` and document
  the default) and emits `kind: "status" { phase: "back_pressure" }`.
  Client drains, sends `session.ack { last_processed_seq: $seq }`,
  and the sample asserts the runtime calls
  `EventLog::evictUpTo($seq)` — observable via a memory probe
  inside the runtime's `EventLog` for the sample only.

- **`agent-versions`** (§7.5). Server registers two
  `AgentEntry { name: "code-refactor", versions: ["1.0.0","2.0.0"], default: "2.0.0" }`.
  Client reads them off `Session::$capabilities->agents`, picks
  `"code-refactor@1.0.0"`, submits — `JobAccepted` carries the
  pinned version back. Second submit with `"code-refactor@9.9.9"`
  throws `AgentVersionNotAvailableException` (`01-spec-delta.md`
  §2). The `AgentRef` value object parses `name@version` via
  `explode('@', $s, 2)`; bare names (no `@`) resolve to the
  inventory's `default`.

- **`lease-expires-at`** (§9.5). Submits with
  `lease_constraints: new LeaseConstraints(expiresAt: $now->modify('+200ms'))`.
  Agent code runs a loop emitting `progress`; after the deadline,
  the next authority op surfaces a `tool_result` carrying
  `LeaseExpiredException`. Runtime then emits `job.error { code:
  "LEASE_EXPIRED", final_status: "error", retryable: false }`. The
  sample asserts the partial progress up to N is intact and that
  `retryable === false`.

- **`cost-budget`** (§9.6). Submit carries `cost.budget: ["USD:1.00"]`.
  Agent emits two `metric { name: "cost.*", value, unit: "USD" }`
  bodies summing $1.12. Runtime decrements a per-currency counter
  using `bcsub('1.00', '0.42', 2)` then `bcsub('0.58', '0.70', 2)`
  (avoiding `float` drift on currency math is the PHP idiom here —
  same reason `ramsey/uuid` chooses `bcmath` internally). Third
  tool call hits `BudgetExhaustedException`, surfaced as a
  `tool_result.error` (the agent can react — emit a partial
  result, return), not a fatal `job.error`. The sample asserts
  one `cost.budget.remaining` metric was emitted between each
  decrement (debounced per spec §9.6).

- **`delegate`** (§9.4 — bounded delegation). Parent agent
  delegates a child with `child.cost.budget ≤ parent_remaining`
  and `child.expires_at ≤ parent.expires_at`. Both bounds are
  computed inside `LeaseSubsetting::bound($parent): EffectiveLease`
  at delegation time, in a no-suspension block — the budget
  snapshot is read once, then validated against the child request.
  A child requesting `["USD:2.00"]` when parent has `"USD:0.40"`
  remaining is rejected with
  `Arcp\Errors\LeaseSubsetViolationException` (`02-current-audit.md`
  §5 row §9.4 cites this as the snapshot-vs-race hazard unique to
  Amp v3 fibers).

## 5. Existing samples retired

The current `samples/` tree (the 14 directories listed in
`samples/README.md` — `subscriptions`, `leases`, `lease_revocation`,
`permission_challenge`, `delegation`, `handoff`, `heartbeats`,
`capability_negotiation`, `resumability`, `reasoning_streams`,
`extensions`, `human_input`, `cancellation`, `mcp`) is replaced
wholesale. They are keyed to the RFC-0001 wire shape called out in
`02-current-audit.md` §1 — the same shape that diverges from v1.0
on every row of the §1 table. They were never runnable
(`samples/README.md` line 9: "Illustrative, not runnable"); the new
tree is runnable by design (§2 — each `run.php` exits 0 on
success).

The new tree is exactly:

```
samples/
  _harness.php
  submit-and-stream/
  delegate/
  resume/
  idempotent-retry/
  lease-violation/
  cancel/
  stdio/
  vendor-extensions/
  custom-auth/
  heartbeat/
  ack-backpressure/
  list-jobs/
  subscribe/
  agent-versions/
  lease-expires-at/
  cost-budget/
  progress/
  result-chunk/
```

No top-level `.php` files in `samples/` other than the shared
`_harness.php`. No `samples/README.md` rewrite is mandated by this
phase — that lives in `08-docs-readme.md`. The retired tree's
naming overlap (current `cancellation/` vs new `cancel/`, current
`heartbeats/` vs new `heartbeat/`, current `delegation/` vs new
`delegate/`, current `extensions/` vs new `vendor-extensions/`,
current `subscriptions/` vs new `subscribe/`) is deliberate — the
new names match the TS directories one-for-one
(`../typescript-sdk/examples/<name>/`) so cross-language CI can run
the same case-keyed assertions.
