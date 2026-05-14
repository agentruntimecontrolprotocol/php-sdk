# 02 — Current SDK Audit

This SDK does **not** currently implement the v1.0 wire spec the v1.1
delta in `01-spec-delta.md` is additive over. It implements an older
revision (`RFC-0001-v2.md` → `../spec/docs/draft-arcp-01.md`), whose
message taxonomy and section numbering differ from
`../spec/docs/draft-arcp-02.md` (v1.0) and
`../spec/docs/draft-arcp-02.1.md` (v1.1).

Phase 02 calls that out before any v1.1 gap analysis — without
acknowledging the v1.0 re-baseline, "add v1.1 features" reads as
patch work when in reality this is the largest piece of work on the
plan.

## 1. Conformance reality vs the TS reference

The PHP SDK's `CONFORMANCE.md` (file at `./CONFORMANCE.md`) is a
**6-line stub** that defers to the README's status section. The TS
SDK's `../typescript-sdk/CONFORMANCE.md` is a 407-line, section-by-
section v1.0 + v1.1 matrix.

That asymmetry alone is a v1.1 deliverable for PHP: a full
`CONFORMANCE.md` keyed to `draft-arcp-02.1.md` §4–§16. The TS file is
the shape to mirror — same column headers (Requirement / Status /
Location), one row per MUST / SHOULD.

What v1.0 (`draft-arcp-02.md`) declares vs what this SDK ships:

| §       | v1.0 wire requirement                                                | PHP-SDK status                                                                                                                                                                                                                          |
| ------- | -------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| §5.1    | Envelope: `arcp: "1"`, `id`, `type`, `session_id`, `event_seq`, …     | **Diverges.** `src/Envelope/Envelope.php` ships a custom envelope keyed to `draft-arcp-01.md` §6.2; no `arcp: "1"` literal version field.                                                                                              |
| §6.1    | Bearer token in `session.hello.payload.auth.token`                   | **Diverges.** Uses `session.open` / `session.authenticate` / `session.challenge` (`src/Messages/Session/`), not `session.hello` → `session.welcome`. Token handling under `src/Auth/{BearerAuth,JwtAuth,NoneAuth}.php`.                  |
| §6.2    | `session.hello` ↔ `session.welcome`; agents inventory; resume_token  | **Missing wire shape.** No `SessionHello`/`SessionWelcome` classes; the closest pair is `SessionOpen` + `SessionAccepted`. Capabilities exist (`Messages/Session/Capabilities.php`) but the features-list / agents-rich-shape do not.   |
| §6.3    | Resume via hello.resume + last_event_seq + buffer replay             | **Partial.** `Messages/Control/Resume.php` exists; `src/Store/EventLog.php` exists; the wire seam is wrong (resume is its own envelope, not part of hello).                                                                             |
| §6.4    | `session.bye` clean close                                            | **Diverges.** `Messages/Session/SessionClose.php` / `SessionEvicted.php` differ in name; semantic intent overlaps.                                                                                                                      |
| §7.1    | `job.submit` → `job.accepted`                                        | **Missing.** No `job.submit`; jobs surface via `job.started`/`job.accepted`/`job.completed`/`job.failed`/`job.cancelled`/`job.heartbeat`/`job.progress`/`job.checkpoint`/`job.schedule` (`Messages/Execution/`) — different lifecycle.    |
| §7.4    | Cancellation                                                         | **Diverges.** `Messages/Control/Cancel.php` + `CancelAccepted`/`CancelRefused`. The v1.0 model treats cancel as part of job lifecycle without separate accept/refuse envelopes.                                                          |
| §8.1    | `job.event` envelope with `kind`                                     | **Missing.** No single `job.event`; events are typed (e.g. `event.emit`, `log`, `metric`, `trace.span`, `stream.chunk`). The v1.0 model unifies these under a single `job.event` envelope with discriminant `kind`.                     |
| §8.2    | Event kinds: log, thought, tool_call, tool_result, status, metric, … | **Diverges.** Tool surfaces (`tool.invoke`/`tool.result`/`tool.error`) are top-level wire messages, not `kind` values inside `job.event`. Logs are top-level `log` envelopes.                                                            |
| §9      | Leases: namespace grammar, subsetting                                | **Partial.** `Runtime/LeaseManager.php` + `Messages/Permissions/{LeaseGranted,LeaseRefresh,LeaseExtended,LeaseRevoked,PermissionRequest,PermissionGrant,PermissionDeny}.php`. Wire shape and lifecycle do not match §9.2 grammar.        |
| §10     | Delegation                                                           | **Partial.** `Messages/Execution/{AgentDelegate,AgentHandoff}.php` exist; subsetting semantics not aligned with §9.4 / §10.                                                                                                              |
| §11     | Trace propagation (W3C 32-hex `trace_id` on envelope)                | **Partial.** `Messages/Telemetry/TraceSpan.php` + IDs (`Ids/TraceId.php`, `Ids/SpanId.php`); envelope-level `trace_id` field needs verification.                                                                                         |
| §12     | 12 canonical error codes                                             | **Diverges.** `src/Errors/` ships 21 exception classes keyed to gRPC status codes (`Aborted`, `DataLoss`, `FailedPrecondition`, `Unavailable`, `Unimplemented`, etc.), not the 12 ARCP codes. See §3.4 below.                            |

Status summary: **the PHP SDK is not v1.0 conformant.** The
v1.1-delta work (`01-spec-delta.md` §1) sits on top of a wire-shape
re-baselining. Phase 10 ranks this as the largest milestone.

## 2. `composer.json` decoded

```
arcp/arcp · Apache-2.0
```

| Field    | Value                                                  | Comment for v1.1                                                                                                            |
| -------- | ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| PHP      | `>=8.4`                                                | Higher than the bootstrap floor (`8.3+`). Phase 03 must defend keeping 8.4 (typed class constants are 8.3; no 8.4-only construct used in current `src/` justifies the floor — likely accidental tightening). |
| ext-pdo, ext-pdo_sqlite | `*`                                  | Currently used by `src/Store/EventLog.php` (SQLite event log). v1.1 should not require SQLite for the client; only the runtime needs it. Split as a `suggest`/`runtime-only` dep.                              |
| ext-mbstring, ext-json   | `*`                                  | Both are bundled in PHP 8.x by default; declaring `ext-json` is redundant on 8.x. Keep `ext-mbstring` only if a hard call site needs it (audit). |
| amphp/amp               | `^3.0`                                | Aligned with Phase 03 concurrency pick. Defend.                                                                              |
| amphp/pipeline          | `^1.0`                                | Aligned with v1.1 subscribe / result_chunk streaming.                                                                        |
| amphp/socket, websocket, websocket-client, websocket-server, byte-stream, process, sync | various | All Amp v3 ecosystem; verify versions current. `websocket-server: ^4` — check Phase 03 picks this same major.                |
| revolt/event-loop       | `^1.0`                                | Required transitively by Amp v3; explicit dep OK.                                                                            |
| psr/log                 | `^3.0`                                | Correct PSR-3 dep.                                                                                                           |
| firebase/php-jwt        | `^7.0`                                | Used by `src/Auth/JwtAuth.php`. v1.0 §6.1 only requires bearer; JWT is a SHOULD-NOT-be-mandatory dep in core. Phase 04: split into `arcp/auth-jwt`.                          |
| symfony/uid             | `^7.0`                                | Used for ULID / UUIDv7. Aligned with Phase 03 IDs pick.                                                                      |
| justinrainbow/json-schema | `^6.0`                              | Not on Phase 03's seed list. **Justify or drop.** v1.0 / v1.1 wire validation does not require JSON Schema at runtime — typed `fromArray` decoders suffice. Likely vestigial from RFC-0001 capability discovery. |
| symfony/console         | `^7.0`                                | **Violates bootstrap rule** "No `symfony/console`-coupled deps in core." Used by `src/Cli/`. Phase 04: extract CLI to a separate Composer package (`arcp/cli`), leave `arcp/arcp` console-free. |
| phpunit                 | `^11.0` (dev)                         | Aligned.                                                                                                                     |
| phpstan + strict-rules  | `^2.0` (dev)                          | Aligned with `level: max`.                                                                                                   |
| vimeo/psalm             | `^6.0 \|\| ^5.0 \|\| dev-master` (dev) | `errorLevel="1"` (strictest). Phase 03 must answer: keep both or drop one. Running both costs CI time for marginal value on a single codebase. |
| friendsofphp/php-cs-fixer | `^3.0` (dev)                        | Aligned with Phase 03 lint/format pick.                                                                                       |
| monolog/monolog         | `^3.0` (dev)                          | PSR-3 reference impl in dev only — consumer provides in prod. Correct.                                                       |

Autoload: `Arcp\\` → `src/`, PSR-4. Test autoload `Arcp\Tests\\` →
`tests/`. **Good — no namespace work needed in Phase 04.**

Scripts: `composer gates` runs `lint → stan → psalm → test`. Keep,
extend with `composer coverage` (already present) and a future
`composer conformance` once the v1.1 conformance harness lands.

## 3. Static analysis & quality gates

| Tool        | Level / setting                                | Don't regress in v1.1                                                                                                       |
| ----------- | ---------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| PHPStan     | `level: max` + `phpstan-strict-rules`          | New code keeps `level: max`. Strict rules enforce `===`, no loose comparisons, no `mixed` returns without annotation.       |
| Psalm       | `errorLevel="1"` (strictest)                   | `sealAllMethods="true"`, `sealAllProperties="true"`. New `final readonly` classes inherit this naturally.                   |
| PHPStan extras | `treatPhpDocTypesAsCertain: false`, `checkUninitializedProperties: true`, `reportAnyTypeWideningInVarTag: true` | Notably strict — keep when introducing new generic value objects.                                                          |
| PHPUnit     | `failOnRisky="true"`, `failOnWarning="true"`, `requireCoverageMetadata="false"` | Phase 07 may set `requireCoverageMetadata="true"` once new code is annotated; existing code will need backfill.            |
| Coverage    | `text outputFile=php://stdout` summary; no minimum gate | Phase 07 must set a numeric coverage floor (≥87% per bootstrap).                                                            |
| Infection   | **Absent.** No `infection.json`.               | Phase 07 must decide: add Infection (mutation), or defend skipping for SDK-scope.                                            |
| Coverage driver | Not pinned (Xdebug or pcov is consumer's choice) | Phase 03/07 should recommend pcov in CI for speed.                                                                          |

Static analysis is already strict. **Do not regress.** v1.1 work
that fails PHPStan max or Psalm `errorLevel="1"` is not landing.

## 4. File tree → target namespace mapping

The current PSR-4 root (`Arcp\\` → `src/`) is correct. The
sub-namespace shape needs work for v1.0 / v1.1 alignment. Phase 04
re-organizes; this audit captures the current layout and the
target.

### 4.1. `src/` namespaces — current

```
Arcp\Auth        — auth schemes (Bearer, JWT, None) + router
Arcp\Cli         — symfony/console commands (serve, send, tail, replay)
Arcp\Client      — ARCPClient + Handlers/ for HITL/permissions
Arcp\Clock       — Clock interface + System/Fake
Arcp\Envelope    — Envelope, MessageType, MessageCatalog, Priority
Arcp\Errors      — 21 exception classes + ErrorCode + ErrorPayload
Arcp\Extensions  — ExtensionNamespace, ExtensionRegistry
Arcp\Ids         — typed ID value objects (12 of them)
Arcp\Json        — EnvelopeSerializer
Arcp\Messages    — 67 message types across 8 subdirs (see §4.2)
Arcp\Runtime     — ARCPRuntime, JobManager, LeaseManager, SubscriptionManager, …
Arcp\Store       — EventLog (SQLite-backed)
Arcp\Trace       — (likely span propagation; verify)
Arcp\Transport   — Transport interface + Memory/Stdio/WebSocket
Arcp\Version     — single-file version constant
```

### 4.2. `Arcp\Messages\*` (67 classes today)

```
Messages\Artifacts\{ArtifactFetch, ArtifactPut, ArtifactRef, ArtifactRelease}
Messages\Control\{Ack, Backpressure, Cancel, CancelAccepted, CancelRefused,
                  CheckpointCreate, CheckpointRestore, Interrupt, Nack,
                  Ping, Pong, Resume}
Messages\Execution\{AgentDelegate, AgentHandoff, JobAccepted, JobCancelled,
                    JobCheckpoint, JobCompleted, JobFailed, JobHeartbeat,
                    JobProgress, JobSchedule, JobStarted, ToolError, ToolInvoke,
                    ToolResult, WorkflowComplete, WorkflowStart}
Messages\Human\{HumanChoiceRequest, HumanChoiceResponse, HumanInputCancelled,
                HumanInputRequest, HumanInputResponse}
Messages\Permissions\{LeaseExtended, LeaseGranted, LeaseRefresh, LeaseRevoked,
                      PermissionDeny, PermissionGrant, PermissionRequest}
Messages\Session\{Auth, Capabilities, PeerInfo, SessionAccepted,
                  SessionAuthenticate, SessionChallenge, SessionClose,
                  SessionEvicted, SessionOpen, SessionRefresh,
                  SessionRejected, SessionUnauthenticated}
Messages\Streaming\{StreamChunk, StreamClose, StreamError, StreamKind, StreamOpen}
Messages\Subscriptions\{Subscribe, SubscribeAccepted, SubscribeClosed,
                        SubscribeEvent, Unsubscribe}
Messages\Telemetry\{EventEmit, LogEvent, MetricEvent, StandardMetrics, TraceSpan}
```

Wire types referenced in those classes (from grep):
`ack`, `agent.delegate`, `agent.handoff`, `artifact.{fetch,put,ref,release}`,
`backpressure`, `cancel`, `cancel.accepted`, `cancel.refused`,
`checkpoint.{create,restore}`, `event.emit`, `human.choice.{request,response}`,
`human.input.{cancelled,request,response}`, `interrupt`,
`job.{accepted,cancelled,checkpoint,completed,failed,heartbeat,progress,schedule,started}`,
`lease.{extended,granted,refresh,revoked}`, `log`, `metric`, `nack`,
`permission.{deny,grant,request}`, `ping`, `pong`, `resume`,
`session.{accepted,authenticate,challenge,close,evicted,open,refresh,rejected,unauthenticated}`,
`stream.{chunk,close,error,open}`, `subscribe`, `subscribe.{accepted,closed,event}`,
`tool.{error,invoke,result}`, `trace.span`, `unsubscribe`,
`workflow.{complete,start}`.

### 4.3. Target namespace (Phase 04 sketch)

```
Arcp\               — Version, top-level
Arcp\Envelope       — Envelope, EnvelopeSerializer (move from Arcp\Json)
Arcp\Session        — Feature, CapabilitySet, AgentInventory, AgentEntry,
                      Hello, Welcome, Resume, Bye, Ack (§6.5), Ping/Pong (§6.4),
                      ListJobs/JobsResponse (§6.6)
Arcp\Job            — Submit, Accepted, Event, Result, Error, Cancel,
                      Subscribe, Subscribed, Unsubscribe
Arcp\Job\Event      — Kind enum, ProgressBody, ResultChunkBody, … (one body
                      type per §8.2 kind)
Arcp\Lease          — Capability, LeaseRequest, EffectiveLease,
                      LeaseConstraints (§9.5 expires_at), CostBudget (§9.6)
Arcp\Errors         — ArcpException, 15 final subclasses (one per spec code),
                      ErrorCode enum returning wire strings
Arcp\Auth           — AuthScheme interface, BearerAuth (in core),
                      (JwtAuth moves to `arcp/auth-jwt` package)
Arcp\Client         — ArcpClient (public seam)
Arcp\Runtime        — Server, JobManager, LeaseManager, EventLog,
                      SubscriptionManager (rename: track v1.1 §7.6 subscribe)
Arcp\Transport      — Transport, MemoryTransport, WebSocketTransport,
                      StdioTransport
Arcp\Trace          — W3C traceparent helpers, span attribute names
Arcp\Cli            — moves to `arcp/cli` package (out of core)
```

Migration cost: roughly **half of `src/` is renamed or relocated.**
This is the bulk of the milestone after the wire-shape rework.

## 5. v1.1 feature × current-SDK gap matrix

Risk legend: **L** (low — additive, no protocol-shape change),
**M** (medium — requires aligning with v1.0 wire shape first),
**H** (high — touches concurrency, cancellation, or distributed
state semantics in a way unique to PHP).

| v1.1 §  | Feature                       | Status      | Target namespace                                  | Risk | PHP-specific risk note                                                                                                                                                                                                                                              |
| ------- | ----------------------------- | ----------- | ------------------------------------------------- | ---- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| §6.2    | Capability negotiation (features list, agents rich shape) | missing | `Arcp\Session\{Feature, CapabilitySet, AgentInventory}` | M | Need an enum (`Feature`) closed by design + `intersect()` on `CapabilitySet`. The hello/welcome wire shape itself must be rebuilt — see §1 row §6.2.                                                                                                                |
| §6.4    | Heartbeat (`session.ping`/`session.pong`) | partial wire, missing protocol mechanics | `Arcp\Session\{Ping, Pong}` | M | `Messages/Control/{Ping,Pong}.php` already exist with type `ping`/`pong`; v1.0 names them `session.ping`/`session.pong`. The driver is a Revolt loop timer with cancellation; **risk:** `php-fpm` deployment can't host a long-lived heartbeat loop — runtime is workers-mode only (see §7). |
| §6.5    | Event ack (`session.ack`)     | partial      | `Arcp\Session\Ack`                                | M | `Messages/Control/Ack.php` exists with type `ack`; v1.0 names it `session.ack` and the body is `{ last_processed_seq }` not whatever the current shape is — verify. EventLog buffer-eviction policy (`src/Store/EventLog.php`) needs an "advisory free up to seq" path. |
| §6.6    | Job listing (`session.list_jobs`/`session.jobs`, cursored) | missing  | `Arcp\Session\{ListJobs, JobsResponse}`           | H | Cursor pagination across a long-lived Amp v3 coroutine needs explicit cancellation through `Amp\DeferredCancellation`; if the SDK reads from `src/Store/EventLog.php` via PDO (SQLite) the listing query is sync but the surrounding loop is fiber-suspending — easy to leak a row-iterator on cancel. |
| §7.5    | Agent versioning (`name@version`)  | missing  | `Arcp\Session\AgentEntry` + parser in `Arcp\Job\Submit` | M | Parser is tiny; the harder part is plumbing through the `JobManager` and `SubscriptionManager` so listings echo `agent: "name@version"`. v1.0 flat-string compat is a `fromFlat` static factory.                                                                    |
| §7.6    | Subscription (`job.subscribe`/`job.subscribed`/`job.unsubscribe`) | partial | `Arcp\Job\{Subscribe, Subscribed, Unsubscribe}` | H | Current `Messages/Subscriptions/Subscribe.php` is a generic "subscribe to a stream" message — v1.0/v1.1 means specifically attaching to a job's event stream from a different session. **Risk:** authorization (`PERMISSION_DENIED` per spec) is per-principal across sessions; current `SubscriptionManager` does not model cross-session principals; needs design work, not just a new class. |
| §8.2.1  | `progress` event kind         | partial      | `Arcp\Job\Event\ProgressBody`                     | L | `Messages/Execution/JobProgress.php` is a top-level message; v1.0 makes progress a `kind` inside `job.event`. Move the body shape, drop the top-level message after v1.0 re-baseline.                                                                                |
| §8.4    | `result_chunk` event + streamed `job.result` | missing | `Arcp\Job\Event\ResultChunkBody`                  | H | `Amp\Pipeline\Pipeline<ResultChunkBody>` for the chunk stream; assembly on the consumer side is a `Generator<string>` decoding `utf8`/`base64`. **Risk:** if the agent throws mid-stream the runtime MUST emit `job.error` with the streamed `result_id`; cleanup paths under fiber cancellation need a finally-block, not destructor reliance. |
| §9.5    | Lease expiration (`lease_constraints.expires_at`) | missing | `Arcp\Lease\LeaseConstraints`                      | M | ISO-8601 UTC `Z` parsing via `DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED)` rejects non-`Z` offsets; surface as `Arcp\Errors\InvalidRequestException` client-side before submit. Enforcement is on every `LeaseManager` check, which is hot — keep the comparison `int $now >= $expiresAtTs`. |
| §9.6    | `cost.budget` capability      | missing      | `Arcp\Lease\CostBudget`                           | H | Per-currency counters require atomic decrement under concurrent fibers. With Amp v3, fibers run cooperatively on a single thread, so a non-atomic read-modify-write between two `Amp\suspend` points is safe **only** if all decrements happen inside a section with no suspension — assert this in code review, not just docs.                                                                                |
| §9.4    | Delegation subsetting (budget, expires_at) | partial | `Arcp\Lease\LeaseSubsetting`                      | M | Existing `Runtime/LeaseManager.php` subsetting needs two new constraints; cross-fiber state read of "parent remaining budget at delegation time" must snapshot, not race.                                                                                            |
| §11     | Trace attrs (`arcp.lease.expires_at`, `arcp.budget.remaining`) | partial | `Arcp\Trace` + OTEL middleware                    | L | `open-telemetry/api` import; pure additive.                                                                                                                                                                                                                          |
| §12     | Three new error codes         | missing      | `Arcp\Errors\{AgentVersionNotAvailable, LeaseExpired, BudgetExhausted}Exception` | L | Three `final readonly` classes; constructor signature matches existing pattern. **Note:** §3.4 above flags that the broader `src/Errors/` set diverges from the 15-code spec — that retirement is part of v1.0 re-baseline, not v1.1. |

## 6. Items that are **not** v1.1 gaps but a v1.0 re-baseline (call out for Phase 10)

These need fixing for any v1.1 work to land on a conformant base.
Phase 10 ranks them ahead of v1.1 features.

1. **Envelope shape.** `arcp: "1"` literal version field; `id`, `type`,
   `session_id`, `trace_id` (W3C 32-hex), `job_id`, `event_seq`,
   `payload` per §5.1; unknown top-level fields ignored.
2. **Session handshake.** Rename `session.open`/`session.accepted`/
   `session.authenticate`/`session.challenge`/`session.rejected`/
   `session.unauthenticated` ➜ `session.hello` / `session.welcome` /
   `session.error`. Auth folds into hello payload.
3. **Job submission.** Replace `job.started` as a top-level submit
   trigger with `job.submit` ➜ `job.accepted`.
4. **Event unification.** Collapse `log` / `metric` / `event.emit` /
   `trace.span` / `tool.invoke` / `tool.result` / `tool.error` /
   `stream.chunk` etc. into `job.event { kind, body }` per §8.2.
5. **Error taxonomy.** Replace gRPC-shaped 21-exception pile with
   the 15-code (12 v1.0 + 3 v1.1) set in `src/Errors/`. Wire string
   on `getCode()`.
6. **`session.bye`** clean close (§6.7) replaces `session.close` /
   `session.evicted`.
7. **`CONFORMANCE.md`** rewritten to mirror
   `../typescript-sdk/CONFORMANCE.md` shape.
8. **`src/Cli/`** moves out of core (Composer hard rule: no
   `symfony/console` in core).
9. **`justinrainbow/json-schema`** removed (no runtime schema
   validation needed — typed decoders do it).

## 7. Deployment-model constraint (PHP-specific)

The current SDK is Amp v3 + Fibers everywhere. That implies the
runtime is **a long-lived worker process**, not a `php-fpm`
request-per-fork worker. A heartbeat timer (§6.4), an event buffer
(§6.5 ack), and a `job.subscribe` listener that lasts for a job's
lifetime do not survive a request-per-fork model — each
request gets a fresh process tree and the loop dies.

**Phase 08 (docs) MUST state this clearly:** the ARCP PHP runtime is
deployed as a daemon (systemd / supervisord / Docker process /
Roadrunner / `amphp/cluster`). `php-fpm` is fine for the *client
side* invoked from a normal web request (a job submitter), not for
the *runtime*.

## 8. Tests baseline

```
tests/
  Unit/            — many files; fixtures under Unit/fixtures/envelopes/
  Integration/
  E2E/
```

25 test files total. Coverage tool unset; numeric floor unset.
Phase 07 starts from here, not from zero. Existing fixtures under
`tests/Unit/fixtures/envelopes/` are likely keyed to the
RFC-0001 wire shape and will need regeneration after the v1.0 re-
baseline lands.

## 9. Hand-off to Phases 3–9

| Phase | What this audit hands them                                                                                                                                                                                                |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 03    | `composer.json` decoded (§2). Defend keeping `amphp/*`; justify dropping or pinning `justinrainbow/json-schema`; choose Infection or defend skipping; reject `symfony/console` in core.                                  |
| 04    | Current → target namespace map (§4). v1.0 re-baseline list (§6). Deployment constraint (§7).                                                                                                                              |
| 05    | Existing `Arcp\Transport\WebSocketTransport` is consumer-side; runtime-side WS upgrade attachment is **not** there — adapter packages need to provide it.                                                                 |
| 06    | Existing six samples (README §Samples) are keyed to RFC-0001 wire shape; will need rewrite. Map to v1.1 18-example list when planning.                                                                                    |
| 07    | PHPUnit 11 + `failOnRisky` + `failOnWarning` already strict; no Infection; no coverage floor; coverage driver unpinned. Phase 07 sets all three.                                                                          |
| 08    | `CONFORMANCE.md` is a 6-line stub; full v1.0+v1.1 matrix needed. README claims §-numbers from RFC-0001 — will need full rewrite after re-baseline.                                                                       |
| 09    | No existing diagrams to extend; greenfield.                                                                                                                                                                              |
