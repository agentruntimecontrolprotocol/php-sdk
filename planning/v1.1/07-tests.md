# 07 — Test Strategy (ARCP v1.1, PHP SDK)

Scope: testing for the v1.0 re-baseline + v1.1 additive surface
(`01-spec-delta.md` §1, `02-current-audit.md` §6). Coverage floor:
**87% lines AND branches** per `BOOTSTRAP.md`. PHPUnit 11 is the
incumbent (`phpunit.xml.dist` line 2; `composer.json` dev-dep
`phpunit/phpunit: ^11.0`); this plan keeps PHPUnit and reconfigures
what's already there rather than introducing a parallel runner.

## 1. Test stack — picks and rulings

| Concern              | Pick                                                     | Ruling                                                                                                                                                                                                                              |
| -------------------- | -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Runner               | **PHPUnit 11** (incumbent)                               | Phase 03 confirms; 25 test files already use it (`tests/Unit/*Test.php`). Pest would be a one-week port for no behavioral gain on a typed value-object SDK.                                                                         |
| Coverage driver      | **pcov** (CI), Xdebug (local opt-in)                     | pcov is line+branch-capable and ~5× faster than Xdebug on a value-object-heavy suite; Xdebug stays available for step-through. Composer dev-require `pcov/clobber` is not needed — pcov is a PECL extension installed in CI image.  |
| Async assertions     | **`amphp/amp` v3 helpers** (`Amp\async`, `Future::await`) | Already a runtime dep (`composer.json`); no extra dev-dep. Tests assert on resolved `Future` values directly. `Amp\PHPUnit\AsyncTestCase` (`amphp/phpunit-util`) is the base class for any test that suspends fibers.                |
| Mutation             | **Infection — added, conditional**                       | Not currently present (Phase 02 §3). Add `infection/infection: ^0.29`, target **MSI ≥ 70%** on `src/Envelope`, `src/Session`, `src/Job`, `src/Lease` only — the value-object + decoder core where mutants are meaningful. Skip on `src/Runtime`, `src/Transport` (I/O-bound; mutation tools produce false positives on async branches). Cost: ~6 CI minutes per PR on 8.4; runs on `main` only via a nightly workflow, not per-PR, to keep PR latency under 3 minutes. |
| Property             | **Eris — skip**                                          | The SDK is typed value-object plumbing; Eris's value would be re-deriving constraints PHPStan max already proves (e.g. "no `null` in a `list<Feature>`"). Where round-trip identity matters (envelope codec), fuzz with a hand-curated fixture set (`tests/Unit/fixtures/envelopes/`) which doubles as conformance evidence. |
| WS loopback          | **`amphp/websocket-server` + `amphp/websocket-client`**  | Already required (`composer.json`); the integration suite binds to `127.0.0.1:0` (ephemeral port) and runs handshake → submit → event → cancel against a real WS, not a mock. Reject `ratchet/pawl` for tests — different fiber model would force a parallel async stack. |
| Fake clock           | **In-house `Arcp\Clock\FakeClock`** (already exists)     | `src/Clock/Fake.php` is in tree; v1.1 lease/heartbeat tests advance it via `$clock->advance($seconds)`. No `lcobucci/clock` or similar dep.                                                                                          |
| HTTP mocking         | **n/a**                                                  | SDK takes a consumer-injected PSR-18 client (Phase 03). Tests pass a `Psr\Http\Client\ClientInterface` double built from `PHPUnit\Framework\MockObject`. No `php-http/mock-client` needed.                                          |

## 2. Layered test plan

Four runtime layers + one conformance layer. Each maps to a
`tests/` subdirectory under the existing `Unit` / `Integration` /
`E2E` split (`tests/Unit/`, `tests/Integration/`, `tests/E2E/`).

### 2.1. Envelope unit (`tests/Unit/Envelope/`, `tests/Unit/Session/`, `tests/Unit/Job/`, `tests/Unit/Lease/`)

For every value object under `Arcp\Session\*`, `Arcp\Job\*`,
`Arcp\Lease\*`, `Arcp\Job\Event\*` (taxonomy from
`02-current-audit.md` §4.3):

1. Build object via constructor with explicit field values.
2. `$envelope = $obj->toEnvelope()` → array.
3. Round-trip through `Arcp\Envelope\EnvelopeSerializer::encode()` →
   JSON bytes → `decode()` → array → `Envelope::fromArray()` →
   `MessageType::fromArray($payload)` → object.
4. Assert equality (`assertEquals` on the `readonly` value object;
   PHP 8.3 value semantics make this stable).

Decoder rejection paths (one test per case, named
`fromArrayRejects<Field><Reason>Test`):

- Missing required field → `Arcp\Errors\MalformedEnvelopeException`
  with the field name in the message.
- Type mismatch (e.g. `event_seq: "1"` string where `int` is
  required) → same exception.
- Unknown top-level envelope field → **ignored** per §5.1 forward-
  compat rule (assert decode succeeds and unknown key is dropped).
- Unknown `kind` inside `job.event.body` → decode succeeds with
  `Arcp\Job\Event\UnknownKindBody` carrying the raw array (forward-
  compat: future v1.2 kinds don't crash a v1.1 client).

Existing `tests/Unit/EnvelopeTest.php`, `MessagesTest.php`,
`MessageCatalogRoundTripTest.php`, `MessageValidationTest.php`
will be **regenerated** after the v1.0 re-baseline lands; they
currently target the RFC-0001 envelope shape (Phase 02 §8).

### 2.2. Message unit (`tests/Unit/Messages/`)

One test per message class asserting its wire-type literal matches
the spec section. Example shape (pseudocode):

```
public function testHelloWireType(): void {
    self::assertSame('session.hello', Hello::WIRE_TYPE);
    self::assertSame(
        Hello::WIRE_TYPE,
        MessageType::Hello->wireType(),
    );
}
```

A drift check: a single `MessageCatalogContractTest` iterates
`MessageType::cases()` and asserts each enum case's `wireType()`
appears verbatim in a checked-in `spec-message-types.json` extracted
from `draft-arcp-02.1.md` §7 + §8 tables. Renaming a wire type in
the SDK without updating the spec extract fails this test loudly —
this is the closest the PHP side gets to a `protoc` contract check.

### 2.3. State-machine unit (`tests/Unit/StateMachine/`)

Three FSMs, each tested in isolation:

- **`SessionStateTest`** — transitions: `Init → HelloSent →
  Welcomed → Active → ByeSent → Closed`; illegal transitions
  (`Init → Active`) throw `IllegalStateTransition`. Heartbeat-lost
  path: `Active → HeartbeatLost → Closed` per §6.4, with the rule
  that jobs survive (asserted by a separate handle).
- **`JobStateTest`** — `Submitted → Accepted → Running → {Result |
  Error | Cancelled}`. **v1.1 terminal codes** (`01-spec-delta.md`
  §1 row §12): a `LEASE_EXPIRED` or `BUDGET_EXHAUSTED` arriving on
  `job.error.payload.error.code` maps to `final_status: "error"`
  (terminal); the job FSM MUST NOT loop back to `Running`. One
  parametric test (`#[DataProvider]`) feeds each terminal code and
  asserts the resulting `Job::status()` is `JobStatus::Error`.
- **`SubscribeStateTest`** — `Attached → Streaming → Detached`.
  Detach paths: `Unsubscribe` (client-initiated), session close
  (transport-initiated). The "no cancel authority" rule (§7.6): a
  `SubscribedJob::cancel()` call from a subscribe-only handle
  throws `Arcp\Errors\NotAuthorizedException` **before** emitting a
  wire `job.cancel` — assertion is "no envelope was written to the
  outbound queue."

### 2.4. Integration (`tests/Integration/`)

Two transports, same scenario suite:

- **`MemoryTransport::pair()`** — in-process pair; runs first
  because it can't fail for network reasons. Existing
  `tests/Integration/HandshakeTest.php`, `JobLifecycleTest.php`,
  `CancellationTest.php`, `ResumeTest.php`, `SubscriptionTest.php`,
  `PermissionLeaseTest.php` are the spine — regenerate for v1.0/v1.1
  wire shapes.
- **WebSocket loopback** — `amphp/websocket-server` bound to
  `127.0.0.1:0` in a `setUpBeforeClass` fixture; tests run the same
  scenarios over the real WS frame layer to exercise the transport.
  This catches frame-fragmentation bugs that `MemoryTransport` hides
  (`02-current-audit.md` §9 hand-off note).

Scenario list (one per file):

- Handshake (§6.2 hello/welcome capability intersection).
- Submit + accept (§7.1).
- Event stream (`progress`, `log`, `metric` kinds; §8.2).
- Result (inline) (§8.3).
- Result (chunked, §8.4) — see `ResultChunkTest` below.
- Cancel (§7.4).
- Resume after disconnect (§6.3).
- Heartbeat loss → session close, job survives (§6.4).
- Subscribe from a second session (§7.6).

### 2.5. Conformance harness (`tests/Conformance/`)

One test method per row in `CONFORMANCE.md` (after the rewrite per
`02-current-audit.md` §6.7). Each method:

1. Names the spec § it covers via a `#[Group('§6.4')]` attribute.
2. Either asserts the requirement or marks itself
   `markTestSkipped('not in v1.1 scope: §X.Y')` for deferred items.

Run as `composer conformance` (script added to `composer.json`
alongside the existing `gates` script per `02-current-audit.md` §2).
Output is a JUnit XML keyed to spec section, parsed by the future
docs pipeline into a status badge per row.

## 3. v1.1-specific tests

One paragraph per feature. Files live under
`tests/Integration/V11/` unless noted.

### 3.1. `HeartbeatTest` (§6.4)

Two paired endpoints over `MemoryTransport`; advance `FakeClock`
past `2 × heartbeat_interval_sec` with one side suppressed (a test
helper drops outbound `session.pong` frames). Assert: (a) the
silenced peer raises `Arcp\Errors\HeartbeatLostException` (wire code
`HEARTBEAT_LOST`); (b) the underlying `Job` returned by an earlier
`submit()` still has `JobStatus::Running` — the runtime MUST NOT
terminate jobs on heartbeat loss (§6.4 second paragraph), only the
session closes. The job-survival assertion is the regression
guard.

### 3.2. `AckTest` (§6.5)

Send a stream of 100 fake events through `Arcp\Runtime\EventLog`
(target namespace; backed by `src/Store/EventLog.php`); send
`session.ack { last_processed_seq: 60 }` from the client side;
**before** the time-based eviction window elapses, assert
`$eventLog->floor()` advanced to 60 — i.e. the ack early-evicted
events 1–60. Without the ack the floor would still be 0. Negative
test: ack with `last_processed_seq` higher than the highest
emitted seq → rejected with `Arcp\Errors\InvalidArgumentException`,
no eviction.

### 3.3. `ListJobsTest` (§6.6)

Submit 25 jobs under principal A and 5 under principal B; principal
A issues `session.list_jobs { limit: 10 }`; assert response carries
10 jobs and a non-null `next_cursor`. Page twice more to drain to
the third response carrying `next_cursor: null`. Cross-principal
visibility test: A's listing **never** includes any of B's 5 jobs
even when an unbounded page is requested; the assertion is on the
exact `job_id` set, not a count, to catch leakage. Filter test:
`status: "running"` returns only running jobs; `agent: "code-refactor"`
returns only that agent.

### 3.4. `SubscribeTest` (§7.6)

Two sessions over `MemoryTransport::pair()`. Session A submits a
job; Session B issues `job.subscribe { job_id, history: true }`.
Assert (a) B receives the historical events buffered in
`EventLog`; (b) B receives subsequent events live as A's agent
emits them; (c) when B calls `subscribedJob->cancel()`, the SDK
throws `Arcp\Errors\NotAuthorizedException` **client-side without
emitting a wire envelope** — spec §7.6 says cancel-attempts from a
subscribe-only handle "are simply not authorized," not that the
runtime MUST respond with `PERMISSION_DENIED`; the wire code only
applies if a client bypasses the SDK and sends `job.cancel` raw.
The test asserts both shapes (client-side block + runtime-side
`PERMISSION_DENIED` on a hand-crafted out-of-band cancel envelope)
so the spec wording is honored either way the cancel arrives.

### 3.5. `AgentVersionsTest` (§7.5)

Runtime registers `code-refactor` versions `1.0.0`, `1.1.0` with
`default: "1.1.0"`. Three subtests: (a) `agent: "code-refactor"`
resolves to `1.1.0` (echoed in `job.accepted.payload.agent`); (b)
`agent: "code-refactor@1.0.0"` resolves to `1.0.0`; (c)
`agent: "code-refactor@2.0.0"` returns `session.error` (or
`job.error` per §7.5) carrying
`code: "AGENT_VERSION_NOT_AVAILABLE"`. Assert the exception type is
`Arcp\Errors\AgentVersionNotAvailableException` and
`$ex->getCode()` returns the spec string verbatim.

### 3.6. `ResultChunkTest` (§8.4)

Agent emits three chunks via the runtime's `Pipeline<ResultChunkBody>`
seam: `(chunk_seq: 0, more: true)`, `(1, true)`, `(2, false)`. The
terminating envelope is `job.result.payload.result_id` referencing
the streamed result; assert (a) client-side iteration via
`foreach ($job->result()->chunks() as $bytes)` yields three frames;
(b) `implode('', $frames)` matches the original payload bytes
exactly (base64 round-trip via the `encoding` field); (c)
`chunk_seq` is monotonic — non-monotonic input throws
`Arcp\Errors\ProtocolViolationException`. **Negative test:** an
agent emits an inline `job.result.payload.result` **and** a
chunked stream for the same job → runtime rejects the second one
with a protocol violation (spec §8.4 "MUST NOT mix").

### 3.7. `LeaseExpiresAtTest` (§9.5)

Lease with `expires_at: $clock->now() + 60`. Pre-expiration: an
authority op (e.g. `tool.invoke` under that lease) succeeds.
`$clock->advance(61)` to push past expiration; the same op now
fails with `Arcp\Errors\LeaseExpiredException`, wire code
`LEASE_EXPIRED`, `retryable: false`. ISO-8601 parsing test: lease
constructed with a local-offset timestamp (`2026-05-14T12:00:00+02:00`)
fails client-side **before submit** with
`Arcp\Errors\InvalidRequestException` — `DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, ...)`
combined with a `Z` literal check (`02-current-audit.md` §5 row
§9.5).

### 3.8. `CostBudgetTest` (§9.6)

Lease with `cost.budget: ["USD:1.00"]`. Agent emits four
`metric { name: "cost.inference", unit: "USD", value: 0.30 }`
events; runtime's per-currency counter goes
`1.00 → 0.70 → 0.40 → 0.10 → -0.20`. Assert: the **fifth**
authority op (any op after the counter ≤ 0) raises
`Arcp\Errors\BudgetExhaustedException`, wire code
`BUDGET_EXHAUSTED`. The decrement-during-fiber-suspension hazard
(`02-current-audit.md` §5 row §9.6) is tested by a parallel
variant that fires two `tool.invoke` calls concurrently via
`Amp\async()` × 2 and asserts the counter never goes negative by
more than one op's worth (last-writer-wins is acceptable;
double-decrement under a race is not).

### 3.9. `CapabilityNegotiationTest` (§6.2)

Three feature scenarios:

- Client offers `{heartbeat, ack, subscribe}`, runtime offers
  `{heartbeat, list_jobs}` → effective set `{heartbeat}`. Assert
  `$session->capabilities->supports(Feature::Ack)` returns
  `false`.
- Client attempts to send `session.ack` against a session where
  `Feature::Ack` is not in the intersection → SDK throws
  `Arcp\Errors\UnnegotiatedFeatureException` **before** writing
  to the transport (library-internal, not a wire code per
  `01-spec-delta.md` §3.4 step 5).
- v1.0 runtime returns `agents: ["a", "b"]` (flat-string compat) →
  client decodes via `AgentInventory::fromFlat()` (`01-spec-delta.md`
  §3.3); using `agent: "a@1.0.0"` against this inventory raises
  `AgentVersionNotAvailableException` client-side before submit.

## 4. Cancellation hygiene

**Rule:** zero `sleep()`, `usleep()`, or `time_nanosleep()` calls in
the test suite. Time advances via `FakeClock::advance()`; cooperative
suspension uses `Amp\delay($seconds, $cancellation)` with a real
`Amp\DeferredCancellation` token whose `cancel()` is called from a
sibling fiber.

Rationale: PHP test runners under CI load (8.3 + 8.4 + coverage
matrix on a 4-core GitHub runner) flake on real-time sleeps of
< 100 ms because the OS scheduler doesn't honor them tightly under
contention. `FakeClock::advance()` is deterministic regardless of
load. `EventLoop::delay()` (from `revolt/event-loop`, already a
transitive dep — `02-current-audit.md` §2) is the **only**
real-time scheduling primitive allowed, and only inside the WS
loopback fixture's `setUp` where a 50 ms grace is needed for
`bind()` to settle.

A PHPStan rule (custom, in `phpstan.neon.dist`) bans
`sleep|usleep|time_nanosleep` under `tests/` via
`\PHPStan\Rules\Rule` checking function-call names. One paragraph
in `tests/README.md` (Phase 08) cites this rule so reviewers know
the floor.

## 5. CI matrix

| Cell        | PHP | Coverage | Mutation | Notes                                                                                                                       |
| ----------- | --- | -------- | -------- | --------------------------------------------------------------------------------------------------------------------------- |
| `8.3-base`  | 8.3 | no       | no       | Floor version. Catches accidental 8.4-only feature use (typed class constants from 8.3 are fine; property hooks would fail). |
| `8.4-cov`   | 8.4 | **yes**  | no       | The one coverage cell — pcov + 87% line+branch gate. PHPUnit + `--coverage-clover` for upload.                              |
| `8.4-mut`   | 8.4 | no       | yes      | Infection on nightly cron only (not per-PR). MSI gate ≥ 70% on the four core namespaces; report posted to `main` only.       |
| `8.5-base`  | 8.5 | no       | no       | Added the day 8.5 hits GA. No deprecation pre-warning needed before then.                                                   |

Why two versions, not one: PHP 8.3 is the **floor** for typed
class constants and `readonly` classes (`01-spec-delta.md` §3.2
uses `readonly class`); 8.4 catches deprecations early — property
hooks and asymmetric visibility aren't used in core SDK but
consumers writing handlers on 8.4 will hit them, and the SDK's
own `Arcp\Runtime\Handlers\*` interfaces should compile clean on
both. Coverage runs on 8.4 only because doubling the coverage
matrix doubles CI minutes for ~zero signal — pcov output is
identical across minor PHP versions.

Composer matrix: `--prefer-lowest` cell on 8.3 (catches accidental
use of `^7.5`-only methods when the floor is `^7.0`); default
versions on 8.4.

## 6. Coverage floor — 87% lines AND branches

Configuration delta to `phpunit.xml.dist` (lines 14–24 currently):

- Add `<report><cobertura outputFile=".phpunit.cache/cobertura.xml"/></report>`
  alongside the existing `<text>` summary.
- Add `<coverage><include>` mirroring `<source>`; PHPUnit 11 needs
  the include set on both blocks.
- Add `<fail>` settings via the CLI flags
  `--coverage-cobertura=... --min-coverage-line=87 --min-coverage-branch=87`
  in the `composer coverage` script. PHPUnit 11 does not gate at
  the XML level; the gate is the script.

Exclusions (add to `<exclude>` in `<source>`):

```
<directory>src/Cli</directory>           <!-- already present; keep -->
<directory>src/Errors</directory>        <!-- 15 trivial subclasses, see below -->
<file>src/Version.php</file>             <!-- single class constant -->
```

Detail on `src/Errors`: the 15 final exception subclasses per
`01-spec-delta.md` §1 row §12 are constructor-promoted
`public readonly string $code; public readonly ?array $details;`
with no logic — they're tagged for `instanceof` discrimination.
Counting them in coverage forces 15 "instantiate and assert
`getCode()`" tests that exercise no behavior. Excluding them keeps
the 87% denominator honest: the floor measures **behavior**, not
PHP boilerplate.

`bin/` is already excluded by living outside the `<source><include>`
block. The `samples/` directory is also outside `<include>`;
sample smoke (`php samples/<name>/run.php` exits 0) runs in a
**separate** CI job not folded into coverage.

No generated stubs in the PHP tree (no `protoc`-style codegen);
this exclusion category is empty for now and noted only so future
work doesn't trip over it.

## 7. Fixtures policy

`tests/Unit/fixtures/envelopes/` is regenerated from
`draft-arcp-02.1.md` §13 worked examples (the seven scenarios at
§13.1–§13.7). Existing fixture `event_emit_full.json` is keyed to
the RFC-0001 wire shape (Phase 02 §8) and will be deleted, not
edited.

File naming: `<spec-section>__<scenario>.json`, e.g.
`13.1__hello_welcome_capability_intersection.json`,
`13.4__result_chunk_three_chunks.json`. Each file is one wire
envelope; multi-envelope scenarios use a numeric suffix
(`13.4__result_chunk_three_chunks.01.json`, `…02.json`, `…03.json`).
A `tests/Unit/fixtures/envelopes/INDEX.md` lists every fixture with
its spec § and one-line description; the file is checked in and
parsed by the conformance harness (§2.5) to assert every §13
example has at least one fixture.

Regeneration is by hand from the spec — there's no `arcp-codegen`
to run. The PHPUnit
`MessageCatalogContractTest` (§2.2) catches drift between fixture
literals and SDK enum constants, so manual regeneration won't
silently desync.

## 8. What not to test

- **PSR interface methods.** `Psr\Log\LoggerInterface`,
  `Psr\Http\Client\ClientInterface`, `Psr\Http\Message\*` —
  consumer-provided. The SDK tests its calls into those
  interfaces (with `MockObject` doubles), not the interface
  contract itself.
- **Third-party library internals.** `amphp/amp`, `amphp/pipeline`,
  `revolt/event-loop`, `symfony/uid`, `firebase/php-jwt` — each
  has its own test suite. The SDK tests the seams where its code
  calls these libraries (e.g. "we await this `Future` with this
  cancellation"), not the libraries' behavior.
- **PHPStan / Psalm rule outputs.** `composer gates` runs both
  (`02-current-audit.md` §2 scripts); the static-analysis pass is
  a separate CI gate from PHPUnit, and a passing PHPStan run is
  the contract — there's no PHPUnit test that "PHPStan finds no
  errors."
- **Symfony Console command argument parsing.** The CLI moves out
  of core to `arcp/cli` (`02-current-audit.md` §6.8); its tests
  live in that separate package.

---

**Summary.** The SDK keeps PHPUnit 11, adds pcov for coverage, gates
on 87% line+branch on a single 8.4 CI cell, and adds Infection
nightly on the four value-object namespaces (target MSI ≥ 70%);
Eris is rejected because PHPStan max already proves the constraints
it would re-derive. Four test layers (envelope unit → message unit
→ state-machine unit → integration over `MemoryTransport` + WS
loopback) plus a conformance harness keyed to `CONFORMANCE.md`
cover the surface. Nine named v1.1 test classes pin the §6.4–§9.6
+ §12 additions. Cancellation hygiene bans real sleeps in favor of
`FakeClock` + `Amp\delay` with tokens; PHP matrix is 8.3 + 8.4
(coverage on 8.4 only).
