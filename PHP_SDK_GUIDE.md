# Idiomatic PHP for Public SDKs

A pragmatic, opinionated guide for writing PHP libraries that will be
consumed by other developers. Optimized for clarity, long-term
maintenance, and machine-assisted refactoring (Claude Code, PHPStan,
Rector). Defaults are strict on purpose — relax them with a written
reason, not vibes.

Target runtime: **PHP 8.3+**. Drop support for EOL versions; do not
carry polyfills for them.

---

## 1. Non-negotiables

- `declare(strict_types=1);` at the top of **every** PHP file. No
  exceptions.
- Follow **PSR-1, PSR-4, PSR-12, PSR-7, PSR-17, PSR-18** where
  applicable. They are the lingua franca of the ecosystem.
- Public surface is **typed end-to-end**: parameters, returns,
  properties. No untyped `$foo`. No `mixed` unless genuinely
  unavoidable, and document why.
- Run PHPStan at `level: max` and Psalm at `errorLevel: 1`. Zero
  baseline. If you must baseline, every entry is a TODO with an issue
  number.
- All public classes, interfaces, methods, and constants have
  docblocks describing **intent**, not what the signature already
  says.
- Semantic Versioning. Breaking changes only on major bumps. Treat
  every `public` and `protected` symbol as a contract.
- Mark internal symbols with `@internal`. Consumers will use them
  anyway; the tag is your defense at the next major.

## 2. Language defaults

- **Classes are `final` by default.** Open for extension only when
  there is a documented extension point (an interface or abstract
  class explicitly designed for it).
- **Properties are `readonly`** unless mutation is part of the
  domain. Prefer `with*()` clone methods over setters.
- **Constructor property promotion** for value objects and DTOs.
  Saves boilerplate, makes intent obvious.
- **Enums** (backed when serialized, pure when not) for every
  closed set of values. Never a `const` list of strings.
- **First-class callable syntax** (`$this->method(...)`) instead of
  string callables.
- **Named arguments** at call sites when a method takes more than
  two parameters or any boolean.
- Avoid magic methods (`__get`, `__set`, `__call`, `__callStatic`).
  They defeat static analysis and surprise consumers.
- Avoid `eval`, variable variables, `extract()`, and dynamic class
  names in public code paths.
- Prefer **early returns** and **guard clauses**. Nesting more than
  two levels deep is a refactor signal.

## 3. Architecture

- **Hexagonal by instinct**, even small: domain in the middle,
  adapters at the edges (HTTP, storage, clock, random).
- **Constructor injection only.** No service locator, no static
  factories that hide dependencies, no `Container::get()` inside
  business logic.
- **No global state.** No singletons. No static mutable properties.
  A `static function make(): self` constructor is fine; a static
  cache of instances is not.
- Pure functions when the domain allows. Side effects live in
  clearly named adapters (`HttpClient`, `Clock`, `RandomSource`).
- **Time and randomness are dependencies.** Inject `ClockInterface`
  (PSR-20) and a random source. Tests will thank you.
- One concept per class. If you find yourself naming a class
  `Manager`, `Helper`, `Util`, or `Service`, the design is unclear.

## 4. Public API surface

- Keep the entry point **tiny**. One root client class is ideal:
  `new Acme\Client($config)`. Discover features via methods, not
  by importing 30 sub-namespaces.
- **Builders or config objects** over long constructor signatures.
  `Acme\Config::default()->withApiKey($key)->withTimeout(5.0)`.
- Return **value objects**, never associative arrays. Arrays-as-
  structs are unsearchable, untyped, and impossible to evolve.
- Accept **interfaces**, return **concrete final classes**. (Or
  return interfaces only when you have multiple implementations
  consumers should swap between.)
- Every public method's failure modes are documented with
  `@throws`, and every documented throwable is a class the consumer
  can actually catch.
- Reserve the `Internal\` namespace for things you do not promise.
  Document it loudly in the README.

## 5. Errors and failure

- One **package-root exception interface**:
  `Acme\Exception\AcmeExceptionInterface extends \Throwable`. Every
  exception your library throws implements it. Consumers can catch
  one type to catch everything from you.
- Build a small hierarchy underneath:
  `ConfigurationException`, `NetworkException`, `ApiException`,
  `ValidationException`. Three to seven types, not thirty.
- **Never** throw `\Exception` or `\RuntimeException` directly from
  library code.
- Wrap third-party exceptions at boundaries. A consumer should
  never have to catch a Guzzle exception that leaked through your
  SDK.
- Use exceptions for **exceptional** failures. Use return types
  (nullable, union, or a small `Result` value object) for expected
  outcomes like "not found" or "validation failed."

## 6. Dependencies

- **Minimize Composer dependencies.** Every dependency is a future
  conflict in someone else's `composer.json`.
- For HTTP: depend on **`psr/http-client`, `psr/http-factory`,
  `psr/http-message`** interfaces only. Let the consumer choose the
  implementation. Use `php-http/discovery` only if you must.
- For logging: `psr/log`. Default to a `NullLogger`.
- For caching: `psr/cache` or `psr/simple-cache`.
- For events: `psr/event-dispatcher`.
- Pin to **caret ranges** in `composer.json` (`^2.3`). Avoid
  wildcard `*` and avoid lock-step pins like `2.3.1`.
- Declare every `ext-*` you use. Yes, even `ext-json`.

## 7. HTTP and I/O

- Build requests with PSR-17 factories. Send with PSR-18 clients.
- Make timeouts, retries, and backoff **configurable and
  documented**. Never ship infinite timeouts.
- Implement idempotency keys for mutating operations where the
  remote API supports them.
- Log at boundaries (request out, response in) at `debug` level
  with the injected logger. Never `var_dump`, never `error_log`.
- Do not parse JSON with `json_decode($x, true)` and pass arrays
  around. Decode into typed value objects at the boundary and
  throw a `MalformedResponseException` on mismatch.
- Stream large payloads. Do not load multi-megabyte response
  bodies into a single string when a stream will do.

## 8. Concurrency and state

- Assume your code runs in long-lived workers (Octane, RoadRunner,
  Swoole, FrankenPHP). **No request-scoped statics.** No
  `register_shutdown_function` for business logic.
- Instances must be safe to reuse across requests. If they are
  not, document it loudly and provide a `reset()` or a factory.

## 9. Testing

- **PHPUnit 11+**, one test class per production class, mirrored
  namespace under `tests/`.
- Aim for ≥85% line coverage on library code. Coverage is a
  smoke detector, not a goal — untested code is broken code, but
  100%-covered nonsense is still nonsense.
- **Contract tests** for every public interface. If you ship
  `HttpClientInterface`, ship a test suite anyone implementing it
  can run.
- Use **in-memory fakes** over mocks where possible. Mocks couple
  tests to implementation; fakes test behavior.
- Snapshot/golden tests for serializers and request builders.
- One assertion per test when feasible. Long tests with many
  assertions hide which behavior actually broke.
- Mutation testing with Infection on critical paths. Aim for
  MSI ≥ 80% on the domain layer.

## 10. Static analysis and tooling

- **PHPStan level max** + bleeding-edge mode. Treat warnings as
  errors in CI.
- **Psalm errorLevel 1** with `findUnusedCode=true` and
  `findUnusedBaselineEntry=true`.
- **PHP-CS-Fixer** or **PHP_CodeSniffer** with PSR-12 + project
  rules. Auto-format on commit.
- **Rector** with the LevelSetList for your minimum PHP version
  and the SetList for code quality. Run it; review the diff;
  commit.
- **Composer audit** in CI.
- **CI matrix**: every supported PHP minor (e.g. 8.3, 8.4) × the
  lowest and highest allowed versions of major dependencies.

## 11. Documentation

- The README answers, in order: what is it, install, 30-second
  example, link to full docs, supported PHP versions, link to
  CHANGELOG, license.
- A `CHANGELOG.md` following **Keep a Changelog**. Every release
  has a date and a `### Added / Changed / Deprecated / Removed /
  Fixed / Security` breakdown.
- An `UPGRADE.md` for every major version with concrete before/
  after code samples.
- Public methods have full PHPDoc with `@param`, `@return`,
  `@throws`, and an `@example` for anything non-obvious.
- Ship a `docs/` directory with topic-based guides
  (authentication, errors, retries, pagination, webhooks, etc.)
  not just API reference.

## 12. Versioning and BC

- Public surface is locked between major versions: signatures,
  thrown exception types, return shapes, behavior.
- Use `#[\Deprecated]` (PHP 8.4+) or `@deprecated` with a `@see`
  pointing at the replacement. Keep deprecated symbols for at
  least one full minor cycle.
- Trigger `E_USER_DEPRECATED` from deprecated public methods so
  consumers see them in their logs.
- Prefer additive changes (new methods, new optional parameters
  with defaults) over mutating existing ones.

## 13. Reducing complexity

Complexity is the enemy of public SDKs. Consumers cannot fix what
they cannot read. These limits are aggressive on purpose.

- **Cyclomatic complexity per function: ≤ 7.** Hard ceiling 10.
  Above that, extract.
- **NPath complexity per function: ≤ 50.**
- **Cognitive complexity per function: ≤ 10** (per SonarSource's
  definition; nested conditionals count double).
- **One return type, no surprises.** Functions that sometimes
  return a value and sometimes have side effects are two
  functions in a trench coat.
- **Boolean parameters are a smell.** If you have one, the
  function does two things. Split it, or use an enum.
- **Max 4 parameters per function.** Beyond that, introduce a
  parameter object or a config DTO.
- **No `else` after `return`.** Flatten with early returns.
- **No more than 2 levels of nesting** (one `if` inside one
  `foreach` is fine; deeper is not). Extract a method.
- **Avoid flag-driven branching across modules.** If three
  classes all check the same boolean, the boolean wants to be a
  polymorphic type.
- Prefer **composition** over inheritance. Inheritance is a
  permanent commitment; composition is a contract.

## 14. Size limits

These are **hard limits** for CI enforcement, not suggestions.

| Scope                | Aspire | Hard max |
| -------------------- | ------ | -------- |
| Line length          | 80     | 100      |
| Function body lines  | 15     | 30       |
| Class lines (total)  | 150    | 300      |
| File lines (total)   | 200    | 400      |
| Public methods/class | 7      | 12       |
| Constructor params   | 4      | 6        |

Notes:

- 80-character line aspiration matches PSR-12's soft limit and
  keeps side-by-side diffs readable on a laptop.
- Class line count excludes docblocks but includes blank lines —
  whitespace is part of cognitive load.
- A file at the hard max is a refactor ticket, not a victory.

## 15. File and project layout

```
src/
  Client.php                  # public entry point
  Config.php                  # immutable config value object
  Exception/
    AcmeExceptionInterface.php
    ApiException.php
    ...
  Http/                       # HTTP boundary adapters
  Internal/                   # @internal, not part of BC promise
  Resource/                   # one file per remote resource
  ValueObject/                # DTOs, enums, request/response shapes
tests/
  Unit/
  Integration/
  Contract/
docs/
composer.json
phpstan.neon.dist
psalm.xml
phpunit.xml.dist
.php-cs-fixer.dist.php
rector.php
infection.json5
CHANGELOG.md
UPGRADE.md
README.md
LICENSE
```

- **One class per file.** Filename matches class name (PSR-4).
- Namespace depth: 3–4 segments max under the vendor prefix.
  Deeper namespaces signal an architectural problem.
- Tests mirror `src/` exactly. `src/Http/RetryingClient.php` →
  `tests/Unit/Http/RetryingClientTest.php`.

## 16. composer.json hygiene

- `name`, `description`, `type: library`, `license` (SPDX
  identifier), `keywords`, `homepage`, `support` block all
  populated.
- `require` lists every runtime dependency including `php` and
  `ext-*`.
- `require-dev` is for tooling and tests only.
- `autoload` and `autoload-dev` use PSR-4 with no fallback paths
  and no classmaps for src/.
- `scripts` block exposes one-word commands: `composer test`,
  `composer stan`, `composer fix`, `composer all` (runs lint +
  stan + test). Consumers and CI both benefit.
- No `minimum-stability: dev` on a release branch. Ever.

## 17. Security defaults

- Treat every input from the network as hostile, including
  responses from APIs you wrote yourself.
- Use `random_int()` and `random_bytes()`. Never `mt_rand` or
  `rand` for anything that touches security.
- Use `hash_equals()` for any string comparison involving
  secrets or signatures.
- Never log secrets, tokens, or full request bodies at info
  level. Redact at the boundary, not at the logger.
- Validate certificate chains. Do not expose a "disable TLS
  verification" config option without a giant docblock warning.

## 18. Things to never do in an SDK

- Echo, `print`, or write to STDOUT/STDERR from library code.
- Call `exit()` or `die()`.
- Install a global error handler, exception handler, or
  autoloader.
- Modify `ini_set` values at import time.
- Depend on the consumer's framework (Laravel, Symfony) from the
  core package. Ship adapters in **separate packages**.
- Bundle the kitchen sink. A "batteries included" SDK is a
  dependency hell trigger. Provide the batteries as optional
  sub-packages.

---

*Last principle: when in doubt, optimize for the consumer reading
your code at 2am during an incident. They are tired, they did not
write this, and they cannot ask you questions. Be kind to them.*
