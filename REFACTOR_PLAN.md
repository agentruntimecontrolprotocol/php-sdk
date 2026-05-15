# REFACTOR PLAN — PHP SDK

Drafted 2026-05-15. Executes the contract in `REFACTOR_PROMPT.md`
against the standards in `PHP_SDK_GUIDE.md`. Baseline numbers live in
`REFACTOR_BASELINE.txt`; the same file is updated when each tool reaches
zero so the gap is always visible.

## 1. Inventory

- 150 PHP files in `src/`, 8,654 lines.
- 25 test files in `tests/`, 3,014 lines.
- Public namespaces under `Arcp\`:
  - `Arcp\Auth` (243 LOC) — token issuance / verification
  - `Arcp\Cli` (257 LOC) — Symfony Console wrappers
  - `Arcp\Client` (456 LOC) — single public class `ARCPClient`
  - `Arcp\Clock` (72 LOC) — PSR-20-equivalent local `ClockInterface`
  - `Arcp\Envelope` (358 LOC) — wire envelope + MessageType registry
  - `Arcp\Errors` (640 LOC) — 22 concrete exceptions + ErrorCode enum
  - `Arcp\Extensions` (156 LOC) — extension namespace handler
  - `Arcp\Ids` (245 LOC) — typed identifier value objects
  - `Arcp\Json` (281 LOC) — Envelope JSON serializer
  - `Arcp\Messages\…` (2,378 LOC) — protocol DTOs (immutable value objects)
  - `Arcp\Runtime` (1,648 LOC) — orchestration glue (the giant class lives here)
  - `Arcp\Store` (212 LOC) — `EventLog` SQLite-backed persistence
  - `Arcp\Transport` (358 LOC) — `Transport` interface + 3 adapters
- 264 PHPUnit tests pass; 598 assertions; full suite < 5s on this host.

## 2. Public surface map

- One entrypoint per role: `Arcp\Client\ARCPClient` and
  `Arcp\Runtime\ARCPRuntime` (the runtime registers tool handlers and
  serves a `Transport`).
- Public DTOs under `Arcp\Messages\…`, all `readonly` with promoted
  constructors and a `fromArray`/`toArray` pair.
- Public exceptions all sit under `Arcp\Errors\` and currently extend
  the abstract `ARCPException` (which itself extends
  `\RuntimeException`).
- No `Internal\` namespace today; runtime helpers (`JobContext`,
  `PendingRegistry`, `LeaseManager`, `SubscriptionManager`, `JobManager`,
  `ArtifactStore`) are public on the same namespace as the runtime.

Locked under this refactor: every `Arcp\*` class, method, constant, or
exception that is reachable from `ARCPClient` or `ARCPRuntime` keeps its
signature and thrown-type set. New marker interfaces and new value
objects are additive.

## 3. Standards delta (gap analysis vs PHP_SDK_GUIDE.md)

### §1 Non-negotiables
- `declare(strict_types=1)` everywhere ✓
- PHPStan max + Psalm L1 wired ✓
- Psalm fails with 2 errors in `tests/Integration/CancellationTest.php`
  — needs to drop to 0.

### §2 Language defaults
- `final` is largely used but the CS-fixer config has
  `'final_class' => false`. Audit and either turn it on or document
  exceptions.
- 22 message DTOs have constructors with > 4 promoted params. The
  guide permits this for value objects but flags ≥7 for review; only
  the worst (e.g. `Capabilities` at 19 params, `Envelope` at 19,
  `StreamChunk` at 8) need parameter objects or builders.

### §3 Architecture
- DI is clean (no singletons, no `getInstance`, no service locator).
- `ClockInterface` is injected ✓
- Runtime/Client take optional defaults but expose constructors with
  9 and 7 params respectively — collapse into a `Config` DTO.

### §4 Public API surface
- Entry points are tiny ✓
- DTOs are value objects ✓
- `@throws` annotations exist on most public methods but coverage is
  uneven (see §5 below).
- No `Internal\` namespace — add one and move runtime/internal helpers
  that aren't part of the BC promise.

### §5 Errors and failure
- ROOT marker mismatch: `ARCPException` is an **abstract class**, not
  an **interface**. The guide wants
  `Arcp\Errors\ARCPExceptionInterface extends \Throwable`.
  - Add the interface. Mark `ARCPException` as implementing it for
    backward compatibility — no concrete exception's external shape
    changes.
- 4 direct `throw new \RuntimeException(...)` in `src/Transport/*` —
  replace with a typed `TransportClosedException` (or wrap under
  `UnavailableException`).

### §6 Dependencies
- `psr/log` ✓
- No PSR HTTP — this SDK uses amphp WebSockets / pipes by design; the
  spec calls for WebSocket / stdio framing. Document this in
  REFACTOR_NOTES.md (PSR-18 is not applicable to a WebSocket SDK; we
  keep the transport-interface abstraction we already have).
- `ext-*` declared ✓.

### §7 HTTP and I/O
- Not applicable: protocol is WebSocket/stdio, not HTTP. Boundary
  logging is already in place via `psr/log`.
- Timeouts are configurable; `infinite` is not the default ✓.

### §8 Concurrency
- No request-scoped statics ✓
- `register_shutdown_function` absent ✓
- Runtime is intended to live for the duration of a process; document
  the safe-reuse guarantees in `docs/`.

### §9 Testing
- 264 tests pass; coverage not yet measured.
- Phase 8 needs a coverage run; gap-fill anything < 50%.
- No `tests/Contract/` directory yet — add contract suites for
  `Transport` and the message-type registry.
- Infection not installed — add it; target MSI ≥ 80% on `Arcp\Json`,
  `Arcp\Envelope`, `Arcp\Errors`, `Arcp\Runtime` core.

### §10 Static analysis and tooling
- Rector missing — add with LevelSetList for PHP 8.3 + CodeQualityList
  + DeadCodeList.
- Infection missing — add config + composer script.
- `composer all` script missing — wire it up.
- Size-limit script missing — add `tools/size-check.php`.

### §11–12 Documentation / versioning
- `README.md`, `CHANGELOG.md`, `CONFORMANCE.md` exist.
- No `UPGRADE.md`. Add one even if it documents "no breaking changes
  in this refactor".
- Public methods missing `@throws` annotations on retry-able paths —
  audit during Phase 9.

### §13 Reducing complexity
- 14 functions exceed the 30-line hard limit; see baseline.
- Cyclomatic complexity not yet measured — add to size-check script.

### §14 Size limits
- 2 files > 400 LOC.
- 2 classes > 300 LOC.
- 14 functions > 30 LOC.
- 100 lines > 100 chars.
- 11 non-DTO functions > 4 params.

### §15 Layout
- `src/Internal/` does not exist; add it and move private helpers
  (`PendingRegistry`, `LeaseManager`, etc. if not part of public API —
  decide per class during Phase 6).

### §16 composer.json hygiene
- `support` block missing.
- New scripts to add: `rector`, `infection`, `audit`, `size-check`,
  `all`.

### §17 Security
- No `mt_rand` / `rand` use in `src/` (uses `bin2hex(random_bytes(...))`).
- No "disable TLS verification" toggles to flag.

### §18 Things never to do in an SDK
- No echo/print/die/exit/var_dump/error_log/ini_set in `src/`.
- No shutdown handlers.

## 4. Size violations (sorted, worst first)

See REFACTOR_BASELINE.txt §"Size violations". The pain ranks as:

1. `Arcp\Runtime\ARCPRuntime` (687 LOC, 603-line class, two functions
   > 75 LOC, 26 methods).
2. `Arcp\Client\ARCPClient` (456 LOC, 389-line class, one 76-line
   function, 14 public methods).
3. `Arcp\Json\EnvelopeSerializer` (281 LOC, two functions > 50 LOC).
4. `Arcp\Messages\Session\Capabilities` (186 LOC, 73-line `fromArray`).
5. `Arcp\Runtime\JobContext` (241 LOC, three permission/human-input
   helpers each > 4 params and one > 30 LOC).
6. `Arcp\Store\EventLog` (212 LOC, one > 30-LOC function).

## 5. Complexity hotspots

Computed by tokeniser only (function body LOC, no cyclomatic tool yet).
The top offenders by body LOC are all in §4. After Phase 6 the only
remaining hotspots should be:

- `EnvelopeSerializer::envelopeFromArray` (will be split per
  message-type group).
- `Capabilities::fromArray` (will be split into helper readers per
  capability section).
- `Runtime::handleToolInvoke` (will be split: validate, lease,
  start-job, emit).

## 6. Risk map

Files where changes can be **behaviour-changing** and therefore need
extra test scaffolding before any edit:

- `src/Json/EnvelopeSerializer.php` — wire format. Add snapshot fixtures
  for every `MessageType` round trip in Phase 8 **before** splitting
  the long methods in Phase 6.
- `src/Errors/*` — exception hierarchy. Wire root marker interface as
  additive (existing class continues to be a concrete base) and ensure
  all 22 concrete types implement it; verify with reflection-based
  tests.
- `src/Transport/*` — three transports throw raw `\RuntimeException`;
  replacement type needs the same catch-shape, so make the new type
  extend `\RuntimeException` and implement the new marker so existing
  `catch (\RuntimeException)` still works.
- `src/Runtime/ARCPRuntime.php` — the dispatch loop. Splitting needs
  the integration suite green between commits.
- `src/Auth/*` — token paths. Snapshot tests before any cleanup.

Everything else is mechanical or DTO-shape work that PHPUnit catches.

## 7. Execution order

Phase boundaries below match `REFACTOR_PROMPT.md` and each maps to one
or more commits. Each commit ends with `composer lint && composer stan
&& composer psalm && composer test` green.

### Phase 2 — Tooling baseline
- C1: Add `rector/rector`, `infection/infection` to `require-dev`.
- C2: Add `rector.php`, `infection.json5`, `tools/size-check.php`.
- C3: Extend `composer.json` scripts with `rector`, `infection`,
  `audit`, `size-check`, `all`. Add `support` block.
- C4: Commit `REFACTOR_PLAN.md` + `REFACTOR_BASELINE.txt` together
  with the tooling files.

### Phase 3 — Mechanical pass
- C5: `composer rector` — apply LevelSet (PHP 8.3) + CodeQuality
  + DeadCode rule-by-rule, one commit per rule cluster.
- C6: `composer fix` — should still be empty; verify and skip.

### Phase 4 — Type safety pass
- C7: Drive Psalm down to 0 (fix the two test errors).
- C8: Audit `mixed` returns — replace with concrete unions where
  trivially derivable.
- C9: Convert any string-typed enum candidate (audit during pass).

### Phase 5 — Exception hierarchy pass
- C10: Introduce `Arcp\Errors\ARCPExceptionInterface`; make existing
  `ARCPException` implement it; export it via `@throws`.
- C11: Add `Arcp\Errors\TransportClosedException` (extends
  `\RuntimeException` AND `implements ARCPExceptionInterface`);
  replace the 4 `\RuntimeException` throws.
- C12: Audit every public method's `@throws`. Add missing annotations.

### Phase 6 — Complexity & size pass
- C13: Split `ARCPRuntime` — extract `Internal\Dispatcher`,
  `Internal\HandshakeNegotiator`, `Internal\ToolInvocationHandler`,
  `Internal\SubscriptionRouter`. Public API is `ARCPRuntime`; the new
  classes live in `Arcp\Internal\Runtime\…`.
- C14: Split `ARCPClient` — extract `Internal\ResponseRouter` for the
  76-line `handle()` and `Internal\ToolInvoker` for `invokeTool`.
- C15: Split `EnvelopeSerializer` — one reader/writer per message
  category (`SerializerForLifecycle`, …`Execution`, …`Permissions`,
  …`Streaming`, …`Subscriptions`, …`Artifacts`, …`Telemetry`,
  …`HumanInput`).
- C16: Split `Capabilities::fromArray` / `::toArray` into private
  per-section parsers.
- C17: Collapse `ARCPRuntime::__construct` (9 params) and
  `ARCPClient::__construct` (7 params) into `RuntimeConfig` and
  `ClientConfig` value objects (additive — keep the existing
  constructors and add a `withConfig(Config)` named-constructor).
- C18: Collapse `JobContext::request*` helpers (5–7 params) behind
  parameter objects (`PermissionRequestSpec`, `HumanInputSpec`,
  `HumanChoiceSpec`).
- C19: Re-wrap every line > 100 chars.

### Phase 7 — Architecture pass
- C20: Move every runtime helper that isn't part of the public BC
  promise into `Arcp\Internal\…`. Document the move in `UPGRADE.md`.
- C21: Verify no static factory hides a dependency — `static fromX`
  on DTOs is fine; anything that touches the clock or random is
  rewritten to constructor injection.

### Phase 8 — Test pass
- C22: Add `tests/Contract/Transport/TransportContractTest.php` —
  third-party-runnable.
- C23: Add `tests/Unit/Json/Snapshot/*` snapshot fixtures for every
  `MessageType` (round-trip via `EnvelopeSerializer`).
- C24: Run coverage, gap-fill files < 50% in `src/`.
- C25: Run Infection on `Arcp\Json`, `Arcp\Envelope`, `Arcp\Errors`,
  `Arcp\Ids`. Drive MSI ≥ 80%.

### Phase 9 — Documentation pass
- C26: Update `README.md` to the §11 structure.
- C27: Add `UPGRADE.md` (covers the additive marker interface and any
  internal-namespace moves).
- C28: Add `docs/{authentication,errors,retries,pagination,
  webhooks}.md`. (Pagination is N/A; webhooks is N/A; document why.)
- C29: Audit every public method's PHPDoc for `@param`/`@return`/
  `@throws`/`@example` completeness.

### Phase 10 — Final verification
- C30: Run `composer all`. Drive each check to green. Update
  `REFACTOR_BASELINE.txt` with the final numbers.
- C31: Write `REFACTOR_REPORT.md`; commit.

## 8. Commit-message conventions

- `chore:` — tooling, configs, dependencies.
- `refactor:` — internal restructuring, no behaviour change.
- `style:` — formatting only.
- `feat:` — additive public surface (new value object, new interface).
- `fix:` — bug discovered during refactor.
- `docs:` — README/CHANGELOG/UPGRADE/docs/ changes.
- `test:` — adding tests; never deleting them to make CI green.

Each commit ends with `composer lint stan psalm test` green.

## 9. Out of scope (logged to REFACTOR_NOTES.md if encountered)

- Switching the transport stack from amphp to a PSR-18 client.
- Splitting Auth into a separate package.
- Wire-protocol changes.
- New CLI commands.
