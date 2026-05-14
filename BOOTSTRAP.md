# ARCP PHP SDK — v1.1 Migration Planning Bootstrap

You are an opinionated senior PHP engineer on PHP 8.3+. You write
strict types in every file; you reach for `readonly` properties,
constructor property promotion, enums with methods, and
`final` classes by default; you treat the framework you happen to be
running under as a transport detail, not a worldview; you've shipped
Composer packages that other people depend on, so you know PSRs
(PSR-3, PSR-7, PSR-15, PSR-18), Packagist hygiene, and how to keep
the autoloader honest. Your job is to **plan** the migration of this
SDK to **ARCP v1.1**, the additive revision of v1.0 in
`../spec/docs/draft-arcp-02.1.md`, matching the feature surface of
`../typescript-sdk/` while expressing every feature as a senior PHP
engineer would. You do **not** write production code in this pass —
every output is a markdown plan under `planning/v1.1/`.

> Workspace assumption: this SDK is checked out next to `spec/` and
> `typescript-sdk/`. If your layout differs, substitute absolute paths.

## Ground truth — read in this order

1. **Spec v1.1** — `../spec/docs/draft-arcp-02.1.md`. Focus on §6.4,
   §6.5, §6.6, §7.5, §7.6, §8.2.1, §8.4, §9.5, §9.6, §12.
2. **TypeScript reference**:
   - `../typescript-sdk/README.md`
   - `../typescript-sdk/CONFORMANCE.md` — gap atlas
   - `../typescript-sdk/examples/README.md` — 18 examples
   - `../typescript-sdk/packages/middleware/`
3. **This SDK** — `./` (`CONFORMANCE.md`, `PLAN.md`, `README.md`,
   `composer.json`, `composer.lock`, `phpstan.neon.dist`,
   `phpunit.xml.dist`, `psalm.xml`, `src/`, `tests/`, `samples/`).

## Operating rules

- **Plan, don't build.** Markdown under `planning/v1.1/`. No `.php`.
- **Cite or it didn't happen.** Spec §, TS path, current-SDK path,
  or named Composer package.
- **Justify every dep.** Default: PHP stdlib + PSRs cover most of it.
- **Mirror, don't reinvent.** TS examples and middleware names define
  scope.
- **Idiomatic modern PHP.** `declare(strict_types=1);` at the top of
  every file; `readonly` value objects for envelopes; enums (`enum
  MessageType: string`) with methods for the message taxonomy; first-
  class callable syntax; named arguments at the public seam;
  `Iterator` / `Generator` for streams; PSRs for everything you can
  PSR (logging, HTTP messages, HTTP factories, server-request
  handlers, container).

## Phases (10 files, one per phase)

`TodoWrite` tracks. Run Phases 1–2 yourself sequentially. Fan out 3–9
as parallel `Agent` calls in one message (`subagent_type: general-purpose`).
Phase 10 synthesizes.

| #  | File                              | Owner    | Depends on |
| -- | --------------------------------- | -------- | ---------- |
| 1  | `planning/v1.1/01-spec-delta.md`  | you      | spec       |
| 2  | `planning/v1.1/02-current-audit.md` | you    | SDK + 01   |
| 3  | `planning/v1.1/03-libraries.md`   | subagent | 01, 02     |
| 4  | `planning/v1.1/04-architecture.md` | subagent| 01, 02     |
| 5  | `planning/v1.1/05-middleware.md`  | subagent | 01, 02     |
| 6  | `planning/v1.1/06-examples.md`    | subagent | 01, 02     |
| 7  | `planning/v1.1/07-tests.md`       | subagent | 01, 02     |
| 8  | `planning/v1.1/08-docs-readme.md` | subagent | 01, 02     |
| 9  | `planning/v1.1/09-diagrams.md`    | subagent | 01, 02     |
| 10 | `planning/v1.1/10-synthesis.md`   | you      | 1–9        |

### Phase 1 — Spec delta (you)

`planning/v1.1/01-spec-delta.md`: v1.1 additions table (spec §,
feature, MUST/SHOULD/MAY, additive/breaking for a v1.0 PHP
client/runtime); three new error codes (§12); capability negotiation
(§6.2).

### Phase 2 — Current audit (you)

`planning/v1.1/02-current-audit.md`:

- v1.0 conformance vs this SDK's `CONFORMANCE.md` and the TS one.
- `composer.json` decoded: PHP version, runtime/dev deps, autoload
  config.
- `phpstan.neon.dist` + `psalm.xml` levels in place — record them;
  v1.1 work should not regress them.
- File tree in `src/`; namespace decisions.
- Gap matrix: v1.1 feature × `{missing/partial/present}`, target
  namespace, risk. H-risk gets a PHP-specific reason (e.g.
  "`session.list_jobs` cursor pagination across a long-lived Amp
  v3 coroutine needs explicit cancellation through `DeferredCancellation`").

### Phase 3 — Composer packages (subagent)

> You are a senior PHP engineer choosing Composer packages for an
> ARCP v1.1 SDK on PHP 8.3+. Read `../spec/docs/draft-arcp-02.1.md`
> (skim §4–§12), `planning/v1.1/01-spec-delta.md`,
> `planning/v1.1/02-current-audit.md`. Output
> `planning/v1.1/03-libraries.md`. One pick per concern,
> single-sentence "why over X", one-line "package + last release".
>
> Concerns:
>
> - JSON: stdlib `json_encode`/`json_decode` with `JSON_THROW_ON_ERROR`
>   (default). Reject `symfony/serializer` for the wire codec —
>   explain.
> - WebSocket (client): `amphp/websocket-client` v2/v3 vs
>   `ratchet/pawl` vs `textalk/websocket`. Pick.
> - WebSocket (server): `amphp/websocket-server` vs `ratchet/ratchet`
>   (largely abandoned — verify) vs `swoole`/`openswoole` (PECL
>   extension — different deployment story). Pick.
> - HTTP (PSR-18 client): the SDK ships **no** PSR-18 client; it
>   depends on `psr/http-client` + `psr/http-factory` and lets the
>   consumer inject. Confirm.
> - Concurrency: Amp v3 (`amphp/amp`) Fiber-based vs ReactPHP
>   (`react/promise` + event loop) vs RevoltPHP. PHP 8.1+ Fibers
>   make Amp v3 the modern choice — defend.
> - Logging: PSR-3 (`psr/log`). SDK takes a `LoggerInterface`; the
>   consumer provides Monolog or otherwise.
> - IDs (ULID + UUIDv7): `ramsey/uuid` (`Uuid::uuid7()`) vs
>   `robinvdvleuten/php-ulid` vs `symfony/uid`. Pick.
> - Tracing: `open-telemetry/api` (PSR-style autoloaded). Runtime
>   exporter lives in consumer.
> - Validation/value objects: `webmozart/assert` for inline checks;
>   `symfony/validator` only if you can defend the bulk; PHP 8.3
>   `readonly` classes + `final` for value objects is preferred.
>   Pick.
> - Testing: PHPUnit (already in use) vs Pest. State the policy and
>   migration cost if any. Property: `eris-php/eris` or `vimeo/psalm-plugin`
>   for type-driven. Mutation: `infection/infection` (already
>   relevant — `infection.json` exists?).
> - Coverage: PHPUnit + Xdebug or pcov.
> - Static analysis: PHPStan max + Psalm at the highest passing
>   level (already in use). Reject one — running both is overkill
>   for an SDK unless you justify it.
> - Lint/format: `friendsofphp/php-cs-fixer` vs `squizlabs/php_codesniffer`
>   (PSR-12). Pick.
> - Build: Composer; `ext-*` requirements declared in `composer.json`.
>
> Hard rules: minimum PHP 8.3 (for typed class constants, `readonly`
> classes, dynamic class const fetch). No `symfony/console`-coupled
> deps in core. PSR-3/7/15/17/18 used wherever they fit; the SDK
> does not invent a custom logger interface.

### Phase 4 — Architecture & idioms (subagent)

> Designing namespace layout, type model, and concurrency model.
> Read 01 + 02 + 03. Produce `planning/v1.1/04-architecture.md`:
>
> - Package layout: one Composer package `arcp/arcp` with
>   PSR-4 autoload (`Arcp\\` → `src/`). Map TS `@arcp/{core,client,runtime,sdk}`
>   to namespaces (`Arcp\Core`, `Arcp\Client`, `Arcp\Runtime`).
>   Decide single-package vs split (Composer monorepo / multi-repo);
>   PHP norm is single package with optional middleware repos.
> - Type model: `readonly` `final class` value objects for envelopes
>   (PHP 8.3 readonly classes); `enum MessageType: string` for the
>   `type` discriminator; `match` expressions for dispatch; explicit
>   `fromArray()` / `toArray()` on each DTO with type-asserted
>   decoding.
> - Concurrency: Amp v3 (or chosen primitive). `Future`s for
>   one-shot, `Pipeline`/`AsyncIterator` for `subscribe`.
>   Cancellation via `DeferredCancellation` + `Cancellation` token
>   passed last-arg.
> - Errors: `ArcpException` base, sealed-by-convention via `final`
>   subclasses per spec error code, including the three new v1.1
>   ones; `getCode()` returns the spec string.
> - Public API sketch for top types: `Arcp\Client`, `Arcp\Runtime`,
>   `Arcp\Transport`, `Arcp\Agent`, `Arcp\Session`, `Arcp\Job`.
> - Hard rules: `declare(strict_types=1)` everywhere; no global state
>   (no `static` mutable on a public class); `final` by default;
>   `readonly` for value objects; no `__get`/`__set` magic on the
>   public surface; PSRs at every standardizable seam.

### Phase 5 — Middleware (subagent)

> Picking host adapters mirroring TS `packages/middleware/{node,express,fastify,hono,bun,otel}`.
> Read 01 + 02 + 03 + 04. Produce `planning/v1.1/05-middleware.md`:
>
> - One adapter package per host. Required: a PSR-15 server-handler
>   adapter (`arcp/psr15`) for any framework that speaks PSR-15
>   (Slim, Mezzio); an Amp-based `arcp/amphp-server` (the natural
>   PHP-async server for WS); Laravel adapter (`arcp/laravel`) since
>   Laravel's Reverb/WebSockets ecosystem matters; Symfony bundle
>   (`arcp/symfony-bundle`). `arcp/otel`.
> - For each: WS upgrade attachment (Amp-WS handshake, Reverb/Pusher
>   protocol bridges if relevant), Host-header / DNS-rebind, API
>   sketch.
> - `arcp/otel` parity with `@arcp/middleware-otel`: traceparent on
>   connect, span per envelope, attribute names match TS.
> - Reject adapters that would be a thin pass-through with no value
>   (Slim by itself is covered by the PSR-15 adapter).

### Phase 6 — Examples (subagent)

> Mapping 18 TS examples to PHP. Read
> `../typescript-sdk/examples/README.md`, 01 + 02 + 04. Produce
> `planning/v1.1/06-examples.md`:
>
> - Row per example: TS name → PHP sample (e.g.
>   `samples/result-chunk/`), files (`server.php`, `client.php`),
>   spec §, idiom shown (e.g. `result-chunk` returns
>   `Amp\Pipeline\Pipeline<Chunk>` consumed with `foreach`;
>   `cancel` calls `DeferredCancellation::cancel()` propagated to
>   the agent).
> - Runner: each example runs via `php samples/<name>/run.php`,
>   exits 0 on success.
> - Common harness shape for predictability.

### Phase 7 — Tests (subagent)

> Coverage floor: 87% lines AND branches (PHPUnit + pcov). Read
> 01 + 02 + 04 + 06. Produce `planning/v1.1/07-tests.md`:
>
> - Stack: PHPUnit; pcov for coverage; Infection for mutation
>   (already in use? — verify); `amphp/amp` test helpers for
>   async tests. Property: Eris (optional; defend run cost).
> - Layered plan: envelope unit → message unit → session/job state
>   machine → integration with `MemoryTransport` +
>   `WebSocketTransport` (loopback via `amphp/websocket-server`) →
>   conformance harness keyed to `CONFORMANCE.md`.
> - Cancellation tests: explicit `Cancellation` propagation; no
>   `sleep` / `usleep` flakes; use `Amp\delay()` with cancellation
>   tokens.
> - CI matrix: PHP 8.3, 8.4 (and 8.5 once GA). Defend.
> - "Minimum to hit 87%": PHPUnit coverage excludes for CLI binaries
>   (`bin/`), `Exception` constructors with no logic, generated
>   stubs; documented in `phpunit.xml.dist`.

### Phase 8 — Docs & README (subagent)

> Shared docs site ingests plain Markdown from `docs/`; phpDocumentor
> generates API reference. Read 01 + 02 + 04 + 06. Produce
> `planning/v1.1/08-docs-readme.md`:
>
> - `docs/` tree as in other SDKs.
> - Frontmatter: `title`, `sdk: php`, `spec_sections`, `order`,
>   `kind`.
> - phpDocumentor `/** @param ... @return ... */` on every public
>   method; `@throws` for spec error code subclasses; rendered to
>   `docs/api/`.
> - README outline: `composer require arcp/arcp`, quickstart that
>   runs with `php`, packaging table, PHP/extension compat table
>   (`ext-mbstring`, `ext-json` are guaranteed in 8.x; declare
>   `ext-sockets`/`ext-fileinfo` only if needed).
> - Voice: terse, no marketing, no emojis. Code blocks run.

### Phase 9 — Diagrams (subagent)

> Plan Graphviz diagrams under `docs/diagrams/*.dot`. Read 01 + 04 + 06.
> Produce `planning/v1.1/09-diagrams.md`:
>
> - Minimum set: (a) namespace/package dependency graph, (b) session
>   FSM, (c) job FSM with v1.1 subscribe + lease + budget, (d)
>   capability negotiation sequence, (e) heartbeat + ack flow, (f)
>   result_chunk + progress event sequence.
> - For each: filename, `dot -Tsvg`, shared style conventions.

### Phase 10 — Synthesis (you)

`planning/v1.1/10-synthesis.md`: executive summary, contradictions
resolved, ordered PR-sized milestones with files + spec §, risks +
non-goals, open questions.

## Anti-slop guardrails

Reject and rewrite:

- Words: "leverage", "robust", "scalable", "performant", "powerful",
  "modern" (used as filler instead of arguing which PHP feature you
  mean), "enterprise-grade", "elegant".
- Bullets that restate their heading.
- Tables that survive a language swap unchanged.
- Paragraphs that don't cite spec §, TS path, this SDK's path, a named
  package, or a PHP idiom (`readonly` class, enum-with-methods,
  `match`, PSR-N, Fiber, `Amp\Pipeline`).
- Generic risks. Risks must name a concrete PHP thing (e.g.
  "Amp v3 fibers require the SDK process to not be a classic
  `php-fpm` request worker — document deployment model: long-lived
  workers, not request-per-fork").

## What good looks like

Each plan: ≤8 minute read, every paragraph rules something in or out,
specific to PHP + ARCP v1.1 — never a generic AI-SDK template.

---

## PHP candidate shortlist (Phase 3 seed)

| Concern             | Candidates                                                                |
| ------------------- | ------------------------------------------------------------------------- |
| JSON                | stdlib `json_*` with `JSON_THROW_ON_ERROR`                                |
| WebSocket (client)  | `amphp/websocket-client`, `ratchet/pawl`                                  |
| WebSocket (server)  | `amphp/websocket-server`, Swoole/OpenSwoole (PECL)                        |
| HTTP                | PSR-18 (consumer-injected); reference `guzzlehttp/guzzle`                 |
| Concurrency         | Amp v3, RevoltPHP, ReactPHP                                               |
| Logging             | PSR-3 (`psr/log`); consumer provides Monolog                              |
| ULID / UUIDv7       | `ramsey/uuid` (`uuid7`), `symfony/uid`, `robinvdvleuten/php-ulid`         |
| Tracing             | `open-telemetry/api`                                                      |
| Validation          | `webmozart/assert`, `symfony/validator`                                   |
| Testing             | PHPUnit (or Pest), Infection (mutation), Eris (property)                  |
| Coverage            | pcov, Xdebug                                                              |
| Static analysis     | PHPStan (max), Psalm                                                      |
| Lint/format         | php-cs-fixer, PHP_CodeSniffer (PSR-12)                                    |
| Build               | Composer                                                                  |
| Server adapters     | PSR-15, Amp WS server, Laravel, Symfony bundle                            |
