# 04 — Architecture & Idioms

Builds on the namespace target in Phase 02 §4.3 and the wire/feature
deltas in Phase 01. Phase 03 owns the dep picks; this phase wires
them into a layout. No PHP code lands here, only signatures and
rules.

## 1. Package layout

One Composer package: **`arcp/arcp`**, PSR-4 `Arcp\\` → `src/` —
exactly the autoload already declared in `composer.json` (Phase 02
§2). Three side packages, each with its own repo and `composer.json`:

| Package         | Why it exists                                                                                                  | Depends on    |
| --------------- | -------------------------------------------------------------------------------------------------------------- | ------------- |
| `arcp/arcp`     | Core wire, transports, client, runtime. The library every consumer pulls in.                                   | `psr/log`, `psr/http-factory`, `psr/http-message`, `amphp/amp`, `amphp/pipeline`, `amphp/websocket-client`, `amphp/websocket-server`, `symfony/uid` |
| `arcp/cli`      | The `Arcp\Cli` tree (Phase 02 §6 item 8) ships `symfony/console`. Keeping it out of core honors the bootstrap "no `symfony/console`-coupled deps in core" rule. | `arcp/arcp`, `symfony/console` |
| `arcp/auth-jwt` | `firebase/php-jwt` is a heavy dep needed only by JWT consumers (Phase 02 §2). The `Arcp\Auth\AuthScheme` interface stays in core; the JWT impl moves out. | `arcp/arcp`, `firebase/php-jwt` |

Middleware adapters (`arcp/psr15`, `arcp/amphp-server`, `arcp/laravel`,
`arcp/symfony-bundle`, `arcp/otel`) are Phase 05's call; they each
ship as their own package on the same pattern.

**Why one core package, not a Composer monorepo?** The TypeScript
SDK splits `@arcp/core`, `@arcp/client`, `@arcp/runtime`,
`@arcp/sdk` because npm publishes per-subdir and tree-shaking bills
the consumer per import. PHP has neither constraint: Composer has
no tree-shaking (autoload is class-name-keyed), and one Packagist
entry per repo is the norm for libraries — Guzzle, Monolog, ramsey/uuid,
PHPUnit are all single-package despite spanning thousands of files.
Multi-package layouts in PHP exist for *frameworks* that ship
independently-usable pieces (Symfony components, Laravel
illuminate/*) where a consumer might want `illuminate/collections`
without `illuminate/database`. An ARCP SDK consumer always needs
envelope + transport + client *or* envelope + transport + runtime;
nobody pulls just `Arcp\Envelope`. Splitting would buy zero
download savings and three extra `composer.json` files to drift.

### 1.1. Namespace map (TS → PHP)

Mirrors Phase 02 §4.3 with one rename: the `Arcp\Core\` prefix the
bootstrap brief mentions is **not** introduced — the current layout
puts envelope/session/job/lease at the top level, and adding a
`Core\` layer doubles the namespace depth for no benefit (it'd
look like `Arcp\Core\Envelope\Envelope` against the current
`Arcp\Envelope\Envelope`). The TS `@arcp/core` namespace is a
publishing artifact; in PHP its members live at the top level
alongside `Arcp\Client` and `Arcp\Runtime`.

| TS package         | PHP namespace(s)                                                                                                  |
| ------------------ | ----------------------------------------------------------------------------------------------------------------- |
| `@arcp/core`       | `Arcp\Envelope`, `Arcp\Session`, `Arcp\Job`, `Arcp\Lease`, `Arcp\Errors`, `Arcp\Transport`, `Arcp\Trace`, `Arcp\Ids`, `Arcp\Clock` |
| `@arcp/client`     | `Arcp\Client`                                                                                                     |
| `@arcp/runtime`    | `Arcp\Runtime`                                                                                                    |
| `@arcp/sdk`        | (re-export equivalent — PHP doesn't need it; consumers import per-class)                                          |

## 2. Type model

Every envelope and every message payload is `final readonly class`
(PHP 8.3 readonly classes), constructor-promoted props, no setters,
no mutators. The `Arcp\Envelope\MessageType` base
(`src/Envelope/MessageType.php`, already in place — read at audit
time) is `abstract readonly class` with `abstract fromArray()` /
`abstract toArray()` / `abstract typeName()`. Keep that shape; the
v1.0 re-baseline (Phase 02 §6) rewrites the *subclasses*, not the
base.

Discriminator enums:

- `enum MessageType: string` — wire `type` field; one case per
  envelope type per spec §5. Closed by design so a v1.2 type drops
  at decode rather than smuggling itself up the stack.
- `enum Feature: string` — already sketched in Phase 01 §3.1; lives
  in `Arcp\Session`, not duplicated here.
- `enum Arcp\Job\Event\Kind: string` — `kind` field on
  `job.event` (§8.2): `Log`, `Thought`, `ToolCall`, `ToolResult`,
  `Status`, `Metric`, `TraceSpan`, `Progress` (§8.2.1), `ResultChunk`
  (§8.4). One case per kind; v1.0 has 7 cases, v1.1 adds 2.

Dispatch uses `match (MessageType::from($wire['type']))` at every
decode seam. PHPStan max + strict-rules forbids a non-exhaustive
`match` on an enum, so adding a v1.2 case forces every dispatcher
to grow an arm or hard-fail static analysis. That is the extension
mechanism Phase 01 §4 names.

Decoding is strict: each `fromArray()` reads `$data['key']` with a
type guard (`is_string`, `is_int`, `is_array`) and throws
`Arcp\Errors\InvalidRequestException` on shape mismatch. Phase 03
chose between `webmozart/assert` and hand-rolled guards; whichever
won, the call site looks the same to `fromArray()`'s caller.

### 2.1. Discriminated-union worked example: `job.event`

PHP has no sum types. The §8.2 / §8.4 event taxonomy needs one. The
shape is `job.event { kind, body }` where `body` is a kind-keyed
object — `progress` bodies have `{ current, total?, units?, message? }`,
`result_chunk` bodies have `{ result_id, chunk_seq, data, encoding, more }`,
etc.

Approximation:

```
namespace Arcp\Job\Event;

enum Kind: string {
    case Log         = 'log';
    case Thought     = 'thought';
    case ToolCall    = 'tool_call';
    case ToolResult  = 'tool_result';
    case Status      = 'status';
    case Metric      = 'metric';
    case TraceSpan   = 'trace_span';
    case Progress    = 'progress';      // §8.2.1
    case ResultChunk = 'result_chunk';  // §8.4
}

abstract readonly class EventBody {
    abstract public function kind(): Kind;
    abstract public static function fromArray(array $body): static;
    /** @return array<string, mixed> */
    abstract public function toArray(): array;
}

final readonly class ProgressBody    extends EventBody { /* §8.2.1 fields */ }
final readonly class ResultChunkBody extends EventBody { /* §8.4 fields  */ }
// … one final readonly subclass per Kind case

final readonly class JobEvent extends MessageType {
    public function __construct(
        public Kind $kind,
        public EventBody $body,
    ) {}

    public static function fromArray(array $data): static {
        $kind = Kind::from((string) $data['kind']);
        $body = match ($kind) {
            Kind::Progress    => ProgressBody::fromArray($data['body']),
            Kind::ResultChunk => ResultChunkBody::fromArray($data['body']),
            // … etc.
        };
        return new self($kind, $body);
    }
}
```

The sealing is by convention (`abstract` + every subclass `final`)
plus the `match` over a closed enum — adding a `Kind` case without
adding a `match` arm fails PHPStan max. That's as close as PHP 8.3
gets to a Rust enum-with-data; it's plenty for ARCP's needs.

## 3. Concurrency model

Phase 03 picked Amp v3 + Fibers, sitting on `revolt/event-loop` —
both already declared in `composer.json` (Phase 02 §2). The seams:

- **One-shot returns** — `Amp\Future<T>`. `job.submit` → `job.accepted`
  is `Future<Job>` (the `Job` value object carries `job_id`).
  `Future::await()` suspends the current fiber.
- **Streams** — `Amp\Pipeline\Pipeline<T>` (already in
  `composer.json` via `amphp/pipeline`). `subscribe()` returns
  `Pipeline<JobEvent>`. The §8.4 result-chunk stream returns
  `Pipeline<ResultChunkBody>` and the caller assembles via
  `foreach`. Pipelines back-pressure naturally; the runtime emits
  faster than the consumer reads, the queue blocks the writer fiber
  at its next `yield`.
- **Cancellation** — `Amp\DeferredCancellation` (the controller),
  `Amp\Cancellation` (the token). Every public method that suspends
  takes a `?Cancellation $cancellation = null` as its **last
  argument** — Amp's idiomatic shape; matches `Amp\Http\Client\Client::request()`
  and `Amp\Socket\connect()`. The token threads down to the
  transport read, the heartbeat timer, and every `Future::await()`
  on the path.

### 3.1. Suspension-point hygiene rule (§9.6)

Cost-budget enforcement (§9.6) reads a per-currency counter,
checks it against an outgoing op, decrements it, then sends the
op. The natural read–modify–write opens a race window if the
fiber suspends between read and write.

Amp v3 fibers run cooperatively on one OS thread — a suspension
point is a `Future::await()` or an explicit `Amp\suspend()`. Between
them, no other fiber runs. The rule, stated as code-review law:

> Between `CostBudget::remaining($ccy)` and
> `CostBudget::decrement($ccy, $amount)` **no `Future::await()`
> and no `Amp\suspend()` may occur.** No I/O, no `delay()`, no
> `Pipeline::continue()`. The block must be straight-line PHP.

PHPStan can't see suspensions, but a custom rule (Phase 07 if it
fits) can flag `await(`/`suspend(` calls inside a method that
also touches `CostBudget`. Without one, this is a documented
review item, not an enforced one. Phase 02 §5 row §9.6 names this
exact risk.

### 3.2. Heartbeat timer

`Revolt\EventLoop::repeat($heartbeatIntervalSec, …)` (already
transitively present) fires `session.ping`; a paired
`EventLoop::delay()` armed on each pong handles the 2× silence
deadline. Both timers cancel on `Session::close()` via
`EventLoop::cancel($id)` — fibers don't auto-clean these. Phase 02
§7 names the deployment constraint: this requires a long-lived
worker process, not `php-fpm`.

## 4. Error model

`Arcp\Errors\ArcpException` is an `abstract` base extending
`\RuntimeException`. Constructor signature:

```
public function __construct(
    ?string $message = null,
    public readonly ?array $details = null,
    ?\Throwable $previous = null,
)
```

`$message` and `$previous` forward to `\RuntimeException::__construct`;
`$details` is the parsed `error.details` object from the wire
payload, promoted to a readonly prop.

Each spec error code (§12) is a `final` subclass with a typed class
constant carrying the wire string:

```
final class LeaseExpiredException extends ArcpException {
    public const string CODE = 'LEASE_EXPIRED';
    public function getCode(): string { return self::CODE; }
}
```

`public const string CODE` is PHP 8.3 typed class constants (Phase
03's PHP-8.3 floor lands this). The subclass overrides
`getCode(): string` — colliding with `\Throwable::getCode(): int`
is intentional: ARCP codes are strings, gRPC codes are ints, and
the override flags the latter at every call site that confuses
them. PHPStan max catches the LSP narrow at the override.

### 4.1. The fifteen subclasses (Phase 01 §1, spec §12)

`PermissionDeniedException`, `LeaseSubsetViolationException`,
`JobNotFoundException`, `DuplicateKeyException`,
`AgentNotAvailableException`, **`AgentVersionNotAvailableException`** (new),
`CancelledException`, `TimeoutException`,
`ResumeWindowExpiredException`, `HeartbeatLostException`,
**`LeaseExpiredException`** (new), **`BudgetExhaustedException`** (new),
`InvalidRequestException`, `UnauthenticatedException`,
`InternalErrorException`.

### 4.2. What gets deleted

The current `src/Errors/` ships 21 gRPC-shaped classes (Phase 02 §1
row §12, Phase 02 §6 item 5). Delete: `AbortedException`,
`DataLossException`, `FailedPreconditionException`,
`UnavailableException`, `UnimplementedException`,
`OutOfRangeException`, `ResourceExhaustedException`,
`AlreadyExistsException`, `DeadlineExceededException`, and any
other gRPC-named class with no §12 match. Keep `ArcpException`,
the `ErrorCode` enum (retighten to 15 cases), and the three
overlap-with-§12 names (rename `NotFoundException` →
`JobNotFoundException` if more specific). Replacements land with
the v1.0 re-baseline milestone, not as v1.1-only work.

`UnnegotiatedFeatureException` lives in `Arcp\Errors` but is **not**
a wire code — Phase 01 §3.4 calls it library-internal; document it
as such in `@throws`.

## 5. Public API sketch — top types

PHP signatures, no bodies. Named arguments at every public seam
with 3+ args.

```
namespace Arcp\Client;

final class ArcpClient {
    public static function open(
        Transport $transport,
        ?CapabilitySet $advertise = null,
        ?LoggerInterface $logger = null,
        ?Cancellation $cancellation = null,
    ): Future; // Future<self>

    public function listJobs(
        ?JobStatus $status = null,
        ?string $agent = null,
        ?DateTimeImmutable $createdAfter = null,
        ?string $cursor = null,
        ?Cancellation $cancellation = null,
    ): Future; // Future<JobsPage> ; §6.6

    public function submitJob(
        string $agent,
        array $input,
        ?LeaseRequest $lease_request = null,
        ?LeaseConstraints $lease_constraints = null,
        ?string $idempotency_key = null,
        ?int $max_runtime_sec = null,
        ?Cancellation $cancellation = null,
    ): Future; // Future<Job> ; §7.1, §9.5

    public function subscribeJob(
        string $job_id,
        ?int $from_event_seq = null,
        bool $history = false,
        ?Cancellation $cancellation = null,
    ): Pipeline; // Pipeline<JobEvent> ; §7.6

    public function close(?Cancellation $cancellation = null): Future;
}

namespace Arcp\Runtime;

final class Server {
    public function __construct(
        Transport $transport,
        AgentRegistry $agents,
        LeaseManager $leases,
        EventLog $eventLog,
        ?LoggerInterface $logger = null,
    ) {}

    public function registerAgent(
        string $name,
        AgentHandler $handler,
        array $versions = [],
        ?string $default_version = null,
    ): void; // §7.5

    public function serveAsync(?Cancellation $cancellation = null): Future;
}

namespace Arcp\Transport;

interface Transport {
    public function send(Envelope $env, ?Cancellation $cancellation = null): Future;
    public function receive(?Cancellation $cancellation = null): Future; // Future<Envelope>
    public function close(?Cancellation $cancellation = null): Future;
}

namespace Arcp\Session;

final readonly class Session {
    public function __construct(
        public string $session_id,
        public string $resume_token,
        public CapabilitySet $effective,     // intersection per §6.2
        public AgentInventory $agents,       // §7.5
        public int $heartbeat_interval_sec,  // §6.4
        public int $event_buffer_seconds,    // §6.5
    ) {}
}

namespace Arcp\Job;

final readonly class Job {
    public function __construct(
        public string $job_id,
        public string $agent,
        public ?string $idempotency_key,
    ) {}
}

namespace Arcp\Lease;

final readonly class Lease {
    /** @param list<string> $capabilities */
    public function __construct(
        public array $capabilities,
        public LeaseConstraints $constraints,
    ) {}
}
```

Every signature uses first-class callable syntax at internal
call-sites where a handler ref is passed (`$transport->send(...)`).
`Future` and `Pipeline` are `Amp\Future` / `Amp\Pipeline\Pipeline`;
templating is via phpDocumentor `@return Future<JobsPage>` and
checked by PHPStan generics — Amp's stubs ship the generic
parameter.

## 6. Hard rules recap

- `declare(strict_types=1);` at the top of every `.php` file —
  PHPStan max already enforces this via existing config
  (Phase 02 §3).
- **No global state.** No `static` mutable property on a public
  class. Clocks, loggers, registries are constructor-injected.
- **`final` by default.** `abstract` only where the type system
  needs a closed seal we can't enforce (`MessageType`, `EventBody`,
  `ArcpException`).
- **`readonly` for value objects** — PHP 8.3 `readonly class`
  marks every prop readonly without per-prop annotation, and
  forbids dynamic props.
- **No `__get` / `__set` / `__call` magic on the public surface.**
  Psalm `sealAllMethods=true` + `sealAllProperties=true`
  (Phase 02 §3) enforces this.
- **PSRs at every standardizable seam.** PSR-3 (`LoggerInterface`,
  consumer-injected); PSR-7 / PSR-17 for HTTP-bridge adapters
  (Phase 05); PSR-15 server-handler shape on the middleware adapter
  for Slim/Mezzio (Phase 05); PSR-18 client for consumer-provided
  HTTP — the SDK ships no PSR-18 impl (Phase 03 decision).
- **First-class callable syntax** (`$obj->method(...)`) over
  anonymous closures wherever a method ref is passed.
- **Named arguments at every public 3+ arg seam** — `submitJob`
  above is the canonical case; any internal helper exposed to
  consumers follows the same rule.
- **No `mixed`** in public signatures. Internal `mixed` survives
  only inside `fromArray()` decode prologues, narrowed before
  return.
