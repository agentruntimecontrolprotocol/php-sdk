# Autonomous PHP SDK Refactor Prompt

> Drop this into a Claude Code session at the root of a PHP library.
> The companion file `PHP_SDK_GUIDE.md` must be present in the
> working tree (or path provided below). **Do not stop for
> permission. Do not summarize and wait. Drive the work to
> completion against the explicit exit criteria at the bottom.**

---

## Role

You are a senior PHP engineer brought in to refactor a public SDK
to a modern, idiomatic, statically-analyzable, maintainable shape.
You have full autonomy over the codebase. You will work
investigation-first, plan before editing, and execute the plan
without mid-task confirmation.

## Inputs

- **Standards reference:** `./PHP_SDK_GUIDE.md` — read this in
  full before doing anything else. It is the contract. If anything
  in this prompt contradicts the guide, the guide wins.
- **Target PHP version:** the highest version declared in
  `composer.json`'s `require.php` constraint, floor at **8.3**.
- **Codebase:** every PHP file under `src/` and `tests/`.

## Operating principles

- **Investigation first, code second.** Read before you write.
  Map the public surface, dependencies, exception hierarchy, and
  test coverage before touching anything.
- **One concern per commit.** Group related edits. If you cannot
  describe a commit in one sentence, split it.
- **Continuous green.** After every logical change, run the full
  toolchain. Do not let the working tree sit in a broken state
  across more than one tool invocation.
- **No mid-task check-ins.** Do not ask "should I continue?",
  "do you want me to also…?", or "let me know if…". Continue.
- **No scope creep.** Stick to the standards in the guide. New
  features, new dependencies, and architectural rewrites beyond
  what the guide mandates are out of scope. Record them in
  `REFACTOR_NOTES.md` for the human.
- **Preserve public behavior.** This is a refactor, not a rewrite.
  Public method signatures, return shapes, and thrown exception
  types are locked unless the guide requires a change and the
  change can be made backward-compatibly via deprecation.

## Phase 1 — Investigation (no edits yet)

Produce and save `REFACTOR_PLAN.md` in the repo root containing:

1. **Inventory:** every file under `src/` with line count, class
   count, public method count, current PHPStan/Psalm baseline
   count attributable to it.
2. **Public surface map:** every public class, method, constant,
   and exception, with their current signatures.
3. **Standards delta:** for each section of `PHP_SDK_GUIDE.md`,
   list the specific violations in this codebase. Group by file.
4. **Size violations:** every file/class/function exceeding the
   hard limits in §14 of the guide, sorted by severity.
5. **Complexity hotspots:** every function with cyclomatic
   complexity > 10 or cognitive complexity > 15, ranked.
6. **Risk map:** any file whose refactor is likely to be
   behavior-changing (anything involving serialization, HTTP,
   authentication, or exception types). These get extra test
   scaffolding before edits.
7. **Execution order:** the sequence of phases below, adapted to
   this codebase's specifics. Include rough commit boundaries.

Once `REFACTOR_PLAN.md` is written, proceed immediately to
Phase 2. Do not wait for review.

## Phase 2 — Tooling baseline

Before touching production code, ensure the toolchain matches the
guide:

- Install or upgrade: `phpstan/phpstan`, `vimeo/psalm`,
  `friendsofphp/php-cs-fixer`, `rector/rector`,
  `phpunit/phpunit`, `infection/infection`.
- Write/update config files: `phpstan.neon.dist` (level: max,
  bleeding-edge enabled), `psalm.xml` (errorLevel: 1,
  findUnusedCode: true), `.php-cs-fixer.dist.php` (PSR-12 + the
  rules below), `rector.php` (LevelSetList for target PHP +
  CodeQualityList + DeadCodeList).
- Add `composer` scripts: `test`, `stan`, `psalm`, `fix`,
  `rector`, `infection`, and `all` that runs everything.
- Add a **size-limit check** (custom script or
  `phpmd`/`phpcpd`) that fails CI when:
  - any file exceeds 400 lines
  - any class exceeds 300 lines
  - any function exceeds 30 lines
  - any function exceeds 4 parameters
  - any line exceeds 100 characters
- Capture the current baseline of failures from every tool into
  `REFACTOR_BASELINE.txt`. This is what you will drive to zero.
- Commit: `chore: establish refactor toolchain baseline`.

## Phase 3 — Mechanical pass (Rector + CS-Fixer)

Run automated tooling first to eliminate trivial work:

- `composer rector` — apply all suggestions, review the diff in
  chunks, commit per logical group (e.g. "refactor: constructor
  property promotion", "refactor: readonly properties").
- `composer fix` — apply CS fixes, commit as
  `style: PSR-12 + project rules`.
- Re-run `composer all`. Note new failures (some Rector rules
  uncover latent type bugs).
- Commit boundary per Rector ruleset, not one giant commit.

## Phase 4 — Type safety pass

Walk the codebase from leaves to roots:

- Add `declare(strict_types=1);` to every file missing it.
- Add return types to every method, including `void` and `never`.
- Add property types. Convert array properties to typed arrays
  documented with `@var list<T>` or `@var array<K, V>` where
  generics are needed.
- Replace associative-array DTOs with **value objects** under
  `src/ValueObject/` (or wherever the existing layout puts them).
- Replace stringly-typed constants and parameters with **enums**.
- Drive PHPStan toward `level: max` with **zero baseline
  entries**. If a genuine `mixed` is unavoidable, document why
  in a `@phpstan-ignore-line` comment with an issue link.

Commit per cohesive area (e.g. "feat(types): introduce
RequestId value object", not "fix types").

## Phase 5 — Exception hierarchy pass

- Ensure a root marker interface exists:
  `Acme\Exception\AcmeExceptionInterface extends \Throwable`
  (replace `Acme` with the project's vendor).
- Every exception thrown from `src/` implements this interface.
- Replace direct throws of `\Exception`, `\RuntimeException`,
  `\InvalidArgumentException` from library code with
  package-specific exceptions.
- Wrap third-party exceptions at boundaries.
- Update `@throws` annotations everywhere.
- If the public exception contract must change, do it via
  deprecation: extend the old type from the new one, mark the
  old `@deprecated`, document in `UPGRADE.md`.

## Phase 6 — Complexity and size pass

Walk the violations list from `REFACTOR_PLAN.md` worst-first:

- For each function over the cyclomatic/cognitive limit: extract
  helper methods, replace conditionals with polymorphism (Strategy
  / State patterns), collapse nested ifs with early returns.
- For each class over the line limit: split by responsibility. If
  it's a "Manager" or "Service" doing five things, it becomes five
  classes.
- For each file over the line limit: same as above — split.
- For each function over 4 parameters: introduce a parameter
  object.
- For each boolean parameter on a public method: split the method
  in two, deprecating the original with a migration note.

After this pass, the size-limit script from Phase 2 must pass
cleanly. **No exceptions, no `@phpstan-ignore`. Refactor until it
passes.**

## Phase 7 — Architecture pass

- Move every `static` factory that hides dependencies behind real
  constructor injection. The factory can remain as a convenience
  if it documents the injected defaults.
- Replace any singleton or static mutable state with proper
  instances managed by the caller.
- Push side effects (clock, randomness, HTTP, filesystem) behind
  injected interfaces. Provide default implementations.
- Ensure HTTP code depends on `psr/http-client`, `psr/http-
  factory`, `psr/http-message` interfaces, not on a concrete
  client. If a concrete client is bundled, move it to a separate
  package or behind an optional dependency.
- Verify the SDK is safe for long-running workers (Octane,
  RoadRunner, FrankenPHP). Document any instance that is not.

## Phase 8 — Test pass

- Mirror `src/` under `tests/Unit/` exactly. Any missing test
  files get scaffolded with at minimum a smoke test per public
  method.
- Replace mock-heavy tests of pure logic with direct calls.
- Add contract test suites for every public interface, runnable
  by third parties.
- Bring line coverage on `src/` to ≥ 85%. If a file is at <50%,
  it is a refactor blocker — finish the test first.
- Run Infection on the domain layer. Drive MSI to ≥ 80%.
- Snapshot tests for every serializer and request builder.

## Phase 9 — Documentation pass

- Update `README.md` to the structure in §11 of the guide.
- Ensure `CHANGELOG.md` exists and follows Keep a Changelog. Add
  an unreleased section documenting every behavior-affecting
  change from this refactor.
- Write `UPGRADE.md` (or a section in the existing one) covering
  every deprecation introduced.
- Every public method has a PHPDoc with `@param`, `@return`,
  `@throws`, and an example for anything non-obvious.
- Create or update `docs/` topic guides for at least:
  authentication, errors, retries, pagination.

## Phase 10 — Final verification

Run, in order:

1. `composer fix --dry-run` — must report no changes.
2. `composer stan` — must exit 0 with no baseline.
3. `composer psalm` — must exit 0 with no baseline.
4. `composer rector --dry-run` — must report no changes.
5. Size-limit script — must exit 0.
6. `composer test` — all green, coverage ≥ 85%.
7. `composer infection` — MSI ≥ 80% on the domain layer.
8. `composer audit` — no advisories.

If any of these fails, do not stop. Fix the failure, re-run from
step 1, repeat until every step is green.

Write `REFACTOR_REPORT.md` summarizing:

- Files added, removed, renamed.
- Public API changes (with BC analysis for each).
- Deprecations introduced.
- Baseline numbers (before vs. after) for each tool.
- Complexity and size metric deltas.
- Anything deferred to `REFACTOR_NOTES.md` and why.

Commit: `chore: complete SDK refactor — see REFACTOR_REPORT.md`.

## Exit criteria (hard gate)

You are **not done** until every one of these is true:

- [ ] `REFACTOR_PLAN.md`, `REFACTOR_BASELINE.txt`, and
      `REFACTOR_REPORT.md` exist and are committed.
- [ ] Every file in `src/` and `tests/` starts with
      `declare(strict_types=1);`.
- [ ] PHPStan `level: max` passes with zero baseline entries.
- [ ] Psalm `errorLevel: 1` passes with zero baseline entries.
- [ ] PHP-CS-Fixer reports no diff.
- [ ] Rector dry-run reports no diff.
- [ ] No file > 400 lines, no class > 300 lines, no function
      > 30 lines, no function > 4 parameters, no line > 100 chars.
- [ ] Aspirational targets met for ≥ 80% of the codebase: lines
      ≤ 80 chars, functions ≤ 15 lines, classes ≤ 150 lines.
- [ ] Every public exception implements the root marker
      interface; no library code throws `\Exception` or generic
      SPL exceptions.
- [ ] All public methods have full PHPDoc.
- [ ] Test coverage on `src/` ≥ 85%; Infection MSI on the
      domain layer ≥ 80%.
- [ ] `composer all` exits 0 from a clean checkout.
- [ ] CI configuration enforces every check above.

## Anti-patterns to refuse

Do **not** do any of the following, even if it would simplify the
work:

- Add a PHPStan or Psalm baseline to make the suite pass. Fix
  the errors.
- Suppress complexity warnings with `@SuppressWarnings`.
- Delete tests to make CI green.
- Replace a real type with `mixed` to silence the analyzer.
- Introduce a new runtime dependency to avoid writing code.
- Lock to a specific patch version of a dependency.
- Use `@phpstan-ignore-next-line` without an accompanying TODO
  comment with a clear reason.
- Skip a phase because "it looked fine."

## Going

Begin with Phase 1 now. Read `PHP_SDK_GUIDE.md` first.
