# 03 — Composer Packages for ARCP v1.1

One pick per concern. Each pick rules a candidate **in** or **out** and
cites the spec §, a TS path, this SDK's path, or the PHP idiom that
makes the call. Versions are approximate latest-stable as of this
writing; `composer.json` is authoritative once the migration lands.

PHP floor: **8.3** (typed class constants, dynamic class-const fetch,
`readonly` classes). The current `composer.json` pins `>=8.4` — Phase
02 §2 flagged this as accidentally tight; this plan rolls it back to
`>=8.3` because no construct in `src/` requires 8.4 (`property hooks`,
asymmetric visibility) and the bootstrap floor is 8.3.

---

## 1. JSON — stdlib `json_encode` / `json_decode` with `JSON_THROW_ON_ERROR`

**Pick:** PHP stdlib. No Composer dep.

**Why over `symfony/serializer`:** the envelope (§5.1 — `arcp`, `id`,
`type`, `session_id`, `event_seq`, `payload`) is decoded by hand-written
`fromArray(array $a): self` static factories on `final readonly` value
objects. `symfony/serializer` resolves these through reflection,
metadata caches, and a normalizer/denormalizer chain, none of which
have a payoff at envelope scale (12 top-level fields + a typed body).
The PHP idiom that wins is `match ($type)` over the `MessageType: string`
enum dispatching to one decoder per case — exhaustive at compile time,
zero reflection at runtime. `JSON_THROW_ON_ERROR` raises
`JsonException` on malformed input, which `Arcp\Envelope\EnvelopeSerializer`
catches and rethrows as `Arcp\Errors\InvalidRequestException` (spec §12
`INVALID_REQUEST`).

**Package + last release:** none (stdlib, PHP 8.3).

---

## 2. WebSocket client — `amphp/websocket-client`

**Pick:** `amphp/websocket-client` v2.

**Why over `ratchet/pawl`:** the SDK is already Fiber-first on Amp v3
(`composer.json` lists `amphp/amp ^3.0`, `amphp/pipeline ^1.0`,
`revolt/event-loop ^1.0`). Pawl is ReactPHP-promise-based; mixing two
event loops in one process is a deployment trap. `textalk/websocket`
is sync-blocking and would freeze the heartbeat loop (§6.4) the moment
a `job.event` Pipeline is iterated. Amp's client returns a `WebsocketConnection`
whose `receive()` suspends the fiber until a frame arrives — exactly
the shape the §7.6 `subscribe` consumer wants behind an `Amp\Pipeline`.

**Package + last release:** `amphp/websocket-client ^2.0` (~2.0.x).

---

## 3. WebSocket server — `amphp/websocket-server`

**Pick:** `amphp/websocket-server` v4.

**Why over Ratchet / OpenSwoole:** Ratchet is effectively unmaintained
(last meaningful release 2022, no PHP 8.3 testing) and ships its own
ReactPHP loop — same dual-loop trap as Pawl. OpenSwoole is a PECL
extension that replaces the userland event loop wholesale; depending
on it forces every consumer to install the extension and forks the
deployment story from the Amp-everywhere model the runtime already
assumes (Phase 02 §7 — long-lived workers, not `php-fpm`). Amp's WS
server slots into the same `Revolt\EventLoop` driver the rest of the
runtime uses; the `session.ping` / `session.pong` timer (§6.4) is a
`EventLoop::repeat()` registration with `DeferredCancellation` tearing
it down on `session.bye`.

**Package + last release:** `amphp/websocket-server ^4.0` (~4.0.x).

---

## 4. HTTP (PSR-18 client) — interfaces only, no implementation

**Pick:** `psr/http-client ^1.0` + `psr/http-factory ^1.0` +
`psr/http-message ^2.0`. The SDK ships **no** PSR-18 implementation.

**Why over bundling Guzzle / Symfony HttpClient:** the only HTTP that
core needs is the optional `discovery` HTTP fetch some hosts use to
resolve a runtime endpoint before opening the WS — and even that
belongs in `arcp/client`, not `arcp/arcp`. Bundling `guzzlehttp/guzzle`
forces a curl-handle pool into a Fiber-based process, which works but
gates the consumer's choice. Consumer-injected `ClientInterface` keeps
the seam at PSR-18; if the consumer is already on Symfony HttpClient
or `php-http/curl-client`, they pass that in. The TS SDK takes the
same shape (host adapter owns the fetch).

**Package + last release:** `psr/http-client ^1.0.3`, `psr/http-factory
^1.0.3`, `psr/http-message ^2.0`.

---

## 5. Concurrency — Amp v3 (`amphp/amp ^3.0` + `revolt/event-loop ^1.0`)

**Pick:** Amp v3.

**Why over ReactPHP / RevoltPHP standalone:** Amp v3 is built on PHP
8.1+ Fibers (`Fiber::suspend()` / `Fiber::resume()`); awaiting a
`Future` is a straight-line `await` call that suspends the fiber, with
no `->then($cb)` callback pyramid. ReactPHP is still
`PromiseInterface::then()` everywhere — readable, but `Arcp\Job\Submit`
returning `Future<JobAccepted>` reads like sync PHP, while a Promise
return reads like sync PHP wearing a paper mask. RevoltPHP is the loop
driver Amp v3 sits on; the SDK depends on both because public Amp
types (`Amp\DeferredCancellation`, `Amp\Pipeline\Pipeline`) appear on
the SDK's surface and Revolt is what schedules them. Picking Revolt
standalone means hand-rolling the future/pipeline primitives Amp
already ships.

The §7.6 `subscribe` seam is `Amp\Pipeline\Pipeline<JobEvent>`, iterated
with `foreach`; cancellation is `Amp\Cancellation` passed as last
argument (see Phase 04 §Concurrency). This is the idiom that justifies
the dep.

**Package + last release:** `amphp/amp ^3.0` (~3.0.x), `amphp/pipeline
^1.0`, `revolt/event-loop ^1.0`.

---

## 6. Logging — PSR-3 (`psr/log ^3.0`)

**Pick:** `psr/log ^3.0`. SDK accepts `Psr\Log\LoggerInterface`;
consumer provides Monolog or any other PSR-3 implementation.

**Why over inventing an `Arcp\Logger` interface:** the SDK does not
own logging policy. Every public class that logs takes a
`LoggerInterface` constructor-promoted property with `new NullLogger()`
default. Monolog is a dev-only dep already (`composer.json` lists
`monolog/monolog ^3.0` in `require-dev`) for use in tests and samples;
production consumers wire their own. Inventing a custom interface
duplicates the PSR-3 shape and forces every consumer into an adapter.

**Package + last release:** `psr/log ^3.0.0`.

---

## 7. IDs (ULID + UUIDv7) — `symfony/uid` (incumbent — confirm)

**Pick:** keep `symfony/uid ^7.0`.

**Why over `ramsey/uuid` and `robinvdvleuten/php-ulid`:** the SDK
already ships `symfony/uid` and `src/Ids/` is built around it. The
v1.1 envelope `id` is a UUIDv7 (monotonic, time-ordered — useful for
event-log scans in `src/Store/EventLog.php`), and `Symfony\Component\Uid\Uuid::v7()`
returns a `UuidV7` `final` class with `toRfc4122()` / `toBinary()` /
`toBase58()` formatters — the same shape `ramsey/uuid` provides via
`Uuid::uuid7()`, but `symfony/uid` adds first-class `Ulid` for the
artifact ref / lease ID surfaces (§9 — `Ulid::generate()` is shorter
than `Uuid::uuid7()->toBase32()`). `robinvdvleuten/php-ulid` is
ULID-only — picking it forces a second package for UUIDv7. Switching
to `ramsey/uuid` for UUIDv7 only would mean rewriting `src/Ids/` for
no functional gain.

**Package + last release:** `symfony/uid ^7.0` (~7.1.x).

---

## 8. Tracing — `open-telemetry/api`

**Pick:** `open-telemetry/api ^1.0` in core; SDK exporter
(`open-telemetry/sdk`) lives in the consumer / OTEL middleware
(`arcp/otel`).

**Why over rolling our own:** §11 names the trace attributes
verbatim (`arcp.lease.expires_at`, `arcp.budget.remaining`), so the
SDK must speak the OTEL `TracerInterface` / `SpanInterface` shapes
anyway. Importing the API-only package keeps core free of the
exporter chain; the SDK calls `Globals::tracerProvider()->getTracer('arcp')`
when present and falls back to a no-op tracer when absent (the OTEL
API package provides `NoopTracerProvider` for this). W3C `traceparent`
parsing into the envelope `trace_id` (32-hex per §5.1) is a 5-line
helper in `Arcp\Trace` — not worth a separate package.

**Package + last release:** `open-telemetry/api ^1.0` (~1.0.x or
1.1.x).

---

## 9. Validation / value objects — PHP 8.3 `final readonly` classes; no validator package

**Pick:** language features. **Drop `justinrainbow/json-schema`.**
**Reject `symfony/validator`. Reject `webmozart/assert`.**

**Why over a validator package:** every wire shape decodes through a
`public static function fromArray(array $a): self` factory on a
`final readonly class` whose constructor is the only assignment site.
The factory does the type asserts inline — `is_string($a['id']) ?? throw new InvalidRequestException(...)` —
and the `readonly` modifier locks the field. Spec §5.1 — 12 envelope
fields, 9 wire types in the v1.1 message taxonomy (Phase 02 §4.3) —
is small enough that hand-written decoders are shorter than the
metadata a validator would consume.

**`justinrainbow/json-schema`:** drop. Phase 02 §2 flagged it as
vestigial. ARCP v1.0 / v1.1 does **not** require JSON Schema at
runtime; the spec lists schemas as documentation, not validation
contracts. The `fromArray` decoders already reject unknown shapes
typed.

**`symfony/validator`:** reject. Annotation/attribute-driven validation
adds a metadata loader, a constraint resolver, and a violation-list
return shape — none of which compose with `final readonly` value
objects whose constructors are the validation seam.

**`webmozart/assert`:** reject. `Assert::string($x)` is a thin wrapper
around `is_string($x)` that throws `InvalidArgumentException`; the SDK
needs to throw `InvalidRequestException` (spec §12 wire code) instead,
so every call site would unwrap and rewrap. Native `is_string` + an
inline `throw` is one line shorter.

**Package + last release:** none added; `justinrainbow/json-schema`
removed.

---

## 10. Testing — PHPUnit (incumbent), reject Pest

**Pick:** keep `phpunit/phpunit ^11.0`. No migration.

**Why over Pest:** the existing 25 test files (`tests/Unit/`,
`tests/Integration/`, `tests/E2E/`) are PHPUnit-class-based. Pest's
DSL (`it()`, `expect()`) is shorter for new tests but the migration
cost is rewriting every file plus retraining every contributor's
mental model. The bootstrap budget is "land v1.1 features," not "swap
test runners for terseness." PHPUnit 11 has `#[Test]` attribute,
`#[DataProvider]`, `#[CoversClass]` — the ergonomic gap to Pest is
narrow on PHP 8.3.

**Async test support:** Amp ships `Amp\PHPUnit\AsyncTestCase` (in
`amphp/phpunit-util`); v1.1 fiber-based tests extend it, getting
`setTimeout` and automatic event-loop teardown between tests.

**Property testing — Eris:** reject for the core suite. `eris-php/eris`
runs N=100 generated cases per property by default; on a v1.1 envelope
round-trip property that's a 100× test-time multiplier with no spec
coverage win the typed `fromArray` decoders + a fuzz corpus under
`tests/Unit/fixtures/envelopes/` don't already give. Pin Eris as a
**deferred** decision for §6.5 ack-buffer state-machine properties
once that lands; not Phase 03 scope.

**Package + last release:** `phpunit/phpunit ^11.0` (~11.4.x),
`amphp/phpunit-util ^3.0` (added for async test cases).

---

## 11. Mutation testing — skip Infection

**Pick:** **skip** `infection/infection`. No `infection.json` in repo
(Phase 02 §3 confirmed); do not add one in v1.1.

**Why:** Infection's value is highest on pure-logic libraries (parsers,
serializers, math) where mutated `>` ↔ `>=` lands on a covered line.
The bulk of `src/` is wire-shape decoders (already covered by
fixture-driven round-trip tests in `tests/Unit/fixtures/envelopes/`)
and fiber-suspending I/O glue (Amp pipelines, Revolt timers) where
Infection's mutators either don't apply or produce mutants that
deadlock the test runner under `Amp\PHPUnit\AsyncTestCase`. Re-evaluate
when the §6.5 ack-buffer eviction policy lands — that's pure logic
worth mutating — but not as a v1.1 dep.

**Package + last release:** none added.

---

## 12. Coverage driver — pcov

**Pick:** `pcov` (PECL extension), declared in CI workflow, not
`composer.json`.

**Why over Xdebug:** pcov is coverage-only; it runs the test suite at
~95% of native speed. Xdebug is a debugger first, coverage collector
second, and adds 3–10× test-runtime overhead in collection mode —
unacceptable for the §6.4 heartbeat tests, where `Amp\delay()` timing
assertions become flaky under debugger-paced execution. The bootstrap
sets a 87% line+branch floor (Phase 07); pcov's branch coverage
support landed in 1.0.6, which is current. The trade-off — pcov
cannot step-debug — is irrelevant for CI.

**Package + last release:** `pcov/clobber` is not the package; the
extension is `ext-pcov` installed via `pecl install pcov` or
distribution package. CI workflow installs and enables it.

---

## 13. Static analysis — PHPStan max, drop Psalm

**Pick:** keep `phpstan/phpstan ^2.0` at `level: max` with
`phpstan-strict-rules`. **Drop `vimeo/psalm`.**

**Why drop Psalm:** Phase 02 §3 records both PHPStan max + Psalm
`errorLevel="1"` running today. On a single ~67-class wire-shape
codebase, the two tools find ~95% of the same defects; the marginal
catch from running both is real but small, and CI time doubles. PHPStan
2.0 ships generic type support, conditional return types, and
`@template` covariance — the Psalm-distinctive features from 2021 are
all in PHPStan now. The asymmetric features (Psalm's taint analysis,
`@psalm-immutable`) don't fire on `final readonly` value objects whose
constructors are the only mutation site. Pick PHPStan because
`phpstan-strict-rules` forbids loose `==`, requires `===`, and bans
`mixed` returns without annotation — the strictest single-tool
posture.

**Package + last release:** `phpstan/phpstan ^2.0` (~2.0.x),
`phpstan/phpstan-strict-rules ^2.0`. `vimeo/psalm` removed from
`require-dev`.

---

## 14. Lint / format — php-cs-fixer (incumbent)

**Pick:** keep `friendsofphp/php-cs-fixer ^3.0`.

**Why over PHP_CodeSniffer (PSR-12):** php-cs-fixer **rewrites** code
to a target style; PHP_CodeSniffer **reports** style violations and
optionally fixes a subset. The SDK's `composer format` script
(Phase 02 §2) is already wired to `php-cs-fixer fix`. Switching
to PHPCS means losing fixers for trailing commas in PHP 8.0+
multiline call sites, `declare(strict_types=1)` insertion, native
function invocation (`\strlen` over `strlen` — small perf win on hot
paths), and `final` class declarations — all of which the bootstrap
operating rules mandate (`final` by default, `declare(strict_types=1)`
everywhere). PHPCS's PSR-12 sniff set is a strict subset of what
php-cs-fixer can enforce.

**Package + last release:** `friendsofphp/php-cs-fixer ^3.0`
(~3.64.x).

---

## 15. Build — Composer; `ext-*` requirements declared in `composer.json`

**Pick:** Composer 2.x. No alternative considered.

**`ext-*` housekeeping:**

- **Drop `ext-json` from `require`.** Bundled in PHP 8.x core (Phase
  02 §2); declaring it is decorative.
- **Drop `ext-pdo` + `ext-pdo_sqlite` from core `require`.** These are
  consumed by `src/Store/EventLog.php`, which is **runtime-side only**.
  Move to a `suggest` entry on `arcp/arcp`; add to `require` on
  `arcp/runtime` if Phase 04 splits packages, or guard with a runtime
  `class_exists(\PDO::class)` check if it stays single-package.
- **Keep `ext-mbstring`** only if an audit confirms a hard call site
  (likely `mb_strlen` on UTF-8 chunks for §8.4 `result_chunk` byte
  counts); otherwise drop — PHP 8.x ships mbstring as a default-on
  extension on most distributions.

**Package + last release:** Composer 2.7.x (operator-level, not a
`composer.json` entry).

---

## 16. Summary — diff vs current `composer.json`

| Slot                  | Current                                 | v1.1 plan                                       |
| --------------------- | --------------------------------------- | ----------------------------------------------- |
| PHP                   | `>=8.4`                                 | `>=8.3` (back to bootstrap floor)               |
| `ext-json`            | declared                                | dropped (PHP 8.x core)                          |
| `ext-pdo` + sqlite    | declared in core                        | moved to runtime-only `suggest`                 |
| JSON                  | stdlib (implicit)                       | stdlib (explicit, with `JSON_THROW_ON_ERROR`)   |
| WS client             | `amphp/websocket-client ^2.0`           | unchanged                                       |
| WS server             | `amphp/websocket-server ^4.0`           | unchanged                                       |
| HTTP                  | not declared                            | add `psr/http-client`, `psr/http-factory`, `psr/http-message` |
| Concurrency           | Amp v3 ecosystem                        | unchanged                                       |
| Logging               | `psr/log ^3.0`                          | unchanged                                       |
| IDs                   | `symfony/uid ^7.0`                      | unchanged (confirmed)                           |
| Tracing               | not declared                            | add `open-telemetry/api ^1.0`                   |
| Validation            | `justinrainbow/json-schema ^6.0`        | **removed** (typed decoders suffice)            |
| JWT auth              | `firebase/php-jwt ^7.0` in core         | move to `arcp/auth-jwt` split package           |
| CLI                   | `symfony/console ^7.0` in core          | move to `arcp/cli` split package                |
| Testing               | `phpunit ^11.0`                         | unchanged; add `amphp/phpunit-util` for fibers  |
| Mutation              | none                                    | skip (defended)                                 |
| Coverage driver       | unpinned                                | `ext-pcov` declared in CI                       |
| Static analysis       | PHPStan max + Psalm                     | PHPStan max only; **drop Psalm**                |
| Lint                  | `php-cs-fixer ^3.0`                     | unchanged                                       |

Three additions (`psr/http-*`, `open-telemetry/api`,
`amphp/phpunit-util`). Three removals (`justinrainbow/json-schema`,
`vimeo/psalm`, `ext-json`). Two relocations
(`firebase/php-jwt` → `arcp/auth-jwt`, `symfony/console` →
`arcp/cli`).
