# 05 — Middleware & Host Adapters

Picking the host-adapter packages that mount `Arcp\Runtime` (per Phase 04 namespace map in `02-current-audit.md` §4.3) into a PHP process and bridge ARCP's WebSocket transport (spec §4) into whatever HTTP / WS stack the consumer already runs.

The TS reference set is `../typescript-sdk/packages/middleware/{node,express,fastify,hono,bun,otel}`. Four of those are Node/Bun runtimes with no PHP analogue; the PHP set folds host adapters into PSR-15 + framework bundles + one async-server pick + the OTEL parity package.

Ground rules pulled from prior phases:

- `02-current-audit.md` §7 — the ARCP runtime is a **long-lived worker**, not a `php-fpm` request worker. Every adapter below assumes a daemon-mode deploy (systemd / Docker / Roadrunner / `amphp/cluster`). Adapters that can only run under `php-fpm` are not viable hosts for the runtime side, only the client side.
- `02-current-audit.md` §2 — `amphp/websocket-server ^4.0` is the pinned WS server major. `amphp/amp ^3.0` + `amphp/pipeline ^1.0` are the concurrency primitives.
- `01-spec-delta.md` §1 row §11 — OTEL adds two v1.1 span attributes: `arcp.lease.expires_at`, `arcp.budget.remaining`. Names match `@arcp/middleware-otel/src/index.ts` exactly (lines 170, 177).

---

## 1. `arcp/psr15`

### Why this adapter

PSR-15 (`psr/http-server-handler` + `psr/http-server-middleware`) is the only neutral way to mount `Arcp\Runtime`'s **HTTP-bridge endpoints** into Slim 4, Mezzio, ReactPHP-Http, or any framework that consumes PSR-15. This adapter rules out shipping `arcp/slim` and `arcp/mezzio` as separate packages: both speak PSR-15 natively, so a single adapter covers both with no glue. It does **not** cover the WebSocket transport, because PSR-15's `ResponseInterface` cannot describe a 101-Switching-Protocols handshake that hands the connection to a different stack — the brief calls this out and we honour it.

### WS upgrade attachment

PSR-15 has no concept of "hand the raw socket to the runtime"; the request handler must return a `ResponseInterface`. Two workable strategies, both documented honestly in the adapter README:

1. **HTTP-bridge mode (the supported path).** The adapter exposes the v1.1 §6.6 `session.list_jobs` listing seam and an authentication-helper endpoint as plain JSON-over-HTTP request handlers. WebSocket transport stays the responsibility of `arcp/amphp-server` (below). The PSR-15 adapter is a **read/control side-channel**, not the primary transport.
2. **Detect-and-redirect mode (degraded).** If `Upgrade: websocket` is present, the handler returns a 426 Upgrade Required with a `Location:` pointing at the configured `amphp/websocket-server` endpoint, plus a `Sec-WebSocket-Version` header naming `arcp.v1`. We do not pretend a PSR-15 stack can serve the WS connection itself.

### Host / DNS-rebind check

The adapter accepts a `list<string> $allowedHosts` constructor argument; the middleware rejects any request whose PSR-7 `getHeaderLine('Host')` does not match the allowlist with a 421 Misdirected Request. This blocks the classic DNS-rebind path where a browser-resident attacker resolves a target name to the loopback runtime. For the WS-upgrade detection path, a `Sec-WebSocket-Protocol` header missing `arcp.v1` (the only registered subprotocol) is rejected with 400, matching spec §4's "WebSocket mandatory for network deployments" intent — the runtime must not accept upgrades it did not advertise.

### Public API sketch

```php
namespace Arcp\Adapter\Psr15;

final readonly class RuntimeRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private \Arcp\Runtime\Server $runtime,
        /** @var list<string> */ private array $allowedHosts,
        private string $upgradeRedirectUrl,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface;
}

final readonly class HostAllowlistMiddleware implements MiddlewareInterface { /* … */ }
```

---

## 2. `arcp/amphp-server`

### Why this adapter

This is the **primary runtime host** per `02-current-audit.md` §7. The current SDK already depends on `amphp/websocket-server ^4.0` (composer.lock confirms), so the adapter is a thin binding from an `Amp\Websocket\Server\WebsocketAcceptor` to `Arcp\Runtime\Server`. It rules out `react/socket` + a third-party WS layer because Amp v3 Fibers (Phase 03 concurrency pick) cannot interoperate cooperatively with ReactPHP's promise-based loop in the same process without `revolt/event-loop` adapter shims that nullify the win.

### WS upgrade attachment

`amphp/websocket-server` v4 exposes an `Amp\Websocket\Server\Websocket` handler interface; `onHandshake` receives a `Request` + `Response` pair where the adapter sets the negotiated subprotocol (`arcp.v1`) before returning, and `handleClient(WebsocketClient $client)` is where each connection's read loop runs as its own fiber. The adapter wires `$client->receive()` (returns `WebsocketMessage` or `null` on close) into the runtime's envelope decoder; outbound envelopes call `$client->sendText($json)`. Closing the session calls `$client->close(Code::NORMAL_CLOSURE, $reason)`. Heartbeat timers (spec §6.4) live in `Arcp\Session\HeartbeatLoop` and use `Revolt\EventLoop::repeat()` cancelled through a `DeferredCancellation` when the session ends — no `php-fpm`-style "request finished" tear-down, the loop runs for the connection's lifetime.

### Host / DNS-rebind check

Implemented in `onHandshake` before returning the upgrade response: read the handshake `Request::getHeader('host')` against the allowlist, reject with `Response(status: 421)` if absent; require `Sec-WebSocket-Protocol: arcp.v1` and either pick that protocol explicitly via `Response::setHeader('sec-websocket-protocol', 'arcp.v1')` or refuse with 400. Origin-header allowlisting is a separate constructor argument because browsers send `Origin` on WS handshakes and runtimes embedded behind a public reverse proxy need to reject cross-origin connect attempts that bypass CORS. Spec §14 "Cross-session subscription audit" is delegated to the runtime layer — this adapter logs the principal and the resolved `session_id` via the injected PSR-3 logger but does not interpret authorization.

### Public API sketch

```php
namespace Arcp\Adapter\AmphpServer;

final readonly class ArcpWebsocketHandler implements Amp\Websocket\Server\Websocket
{
    public function __construct(
        private \Arcp\Runtime\Server $runtime,
        /** @var list<string> */ private array $allowedHosts,
        /** @var list<string> */ private array $allowedOrigins,
        private \Psr\Log\LoggerInterface $logger,
    ) {}

    public function onHandshake(Request $request, Response $response): Response;
    public function handleClient(WebsocketClient $client, Request $request, Response $response): void;
}

function serve(\Arcp\Runtime\Server $runtime, string $listen = '0.0.0.0:8080'): void;
```

---

## 3. `arcp/laravel`

### Why this adapter

Laravel's WS ecosystem is Reverb (Pusher-protocol-shaped) and `pusher/pusher-php-server`. Neither speaks the ARCP envelope wire format from spec §5 — Reverb broadcasts events keyed by channel names with a Pusher JSON shape, which is not interchangeable with `{ arcp: "1", id, type, session_id, ... }`. So this adapter does **not** bridge Reverb. Instead it ships:

- A service provider that binds `Arcp\Runtime\Server` and `Arcp\Client\Client` into the container.
- An Artisan command `arcp:serve` that boots `arcp/amphp-server` inside an Octane long-running worker (Swoole/RoadRunner driver), reusing Laravel's container for agent/tool resolution.
- A route macro `Route::arcpHttp('/arcp/control', ...)` mounted via the PSR-15 bridge for the §6.6 listing endpoint.

This rules out shipping a "Reverb integration" — Reverb's protocol is NOT a substitute for ARCP transport, and bridging the two would mean re-encoding every envelope twice with no semantic gain.

### WS upgrade attachment

Same Amp WS server as §2, hosted inside an Octane worker. Octane is required because classic `php-fpm` cannot hold the fiber loop alive across requests (`02-current-audit.md` §7). The Artisan command boots `amphp/cluster` workers if scaling beyond one process is needed; sticky-session routing at the load balancer is the consumer's responsibility (Laravel's session driver does not apply to ARCP — `session_id` is the ARCP session, not the HTTP session). For Laravel apps that already run Reverb on `:8080`, document the ARCP listener on a distinct port (e.g. `:8081`) — one socket, one protocol.

### Host / DNS-rebind check

Reuses the `HostAllowlistMiddleware` from `arcp/psr15` for the control-plane routes, and forwards `config('arcp.allowed_hosts')` plus `config('arcp.allowed_origins')` into the Amp WS handler. Laravel's `TrustHosts` middleware is **not** a substitute — it operates on the HTTP request stack, which the Amp WS handler does not pass through. Document this in the adapter's `config/arcp.php` stub.

### Public API sketch

```php
namespace Arcp\Adapter\Laravel;

final class ArcpServiceProvider extends \Illuminate\Support\ServiceProvider {
    public function register(): void;
    public function boot(): void;
}

final class ServeCommand extends \Illuminate\Console\Command {
    protected $signature = 'arcp:serve {--host=0.0.0.0} {--port=8080}';
    public function handle(\Arcp\Runtime\Server $runtime): int;
}
```

---

## 4. `arcp/symfony-bundle`

### Why this adapter

Symfony has a real DI container, real tagging, and real console subsystem — none of which the core `arcp/arcp` package depends on (`02-current-audit.md` §2 calls out that `symfony/console` must leave core). The bundle wires `Arcp\Runtime\Server` as a service, autoconfigures agents tagged `arcp.agent` and tools tagged `arcp.tool`, and exposes a `arcp:serve` console command that delegates to the same Amp WS handler from §2. CLI behaviour beyond `arcp:serve` lives in the separate `arcp/cli` package (per Phase 04's CLI extraction) — the bundle does **not** re-export it.

### WS upgrade attachment

Symfony's HttpKernel terminates a request and returns a `Response`; it does not hold sockets open. So the bundle hosts the Amp WS server as a long-lived process spawned by the `arcp:serve` command, identical to §2/§3. Two deployment shapes are supported:

1. **Symfony Runtime component + `amphp/cluster`** — the `bin/console arcp:serve` invocation becomes the process entrypoint; Symfony's bootstrap initializes the container, then control transfers to the Amp event loop.
2. **Roadrunner + `spiral/roadrunner-symfony`** — RR holds the process alive across HTTP requests; the ARCP fiber loop runs alongside the request worker on a separate port (config `arcp.listen_address`). The two share the same container instance.

### Host / DNS-rebind check

`config/packages/arcp.yaml` exposes `arcp.allowed_hosts` and `arcp.allowed_origins` as bundle parameters, injected into the Amp WS handler. The bundle does **not** rely on Symfony's `framework.trusted_hosts` — that config is enforced by `Symfony\Component\HttpFoundation\Request::setTrustedHosts()` which the WS handler does not invoke. Documented as a separate setting to avoid the trap.

### Public API sketch

```php
namespace Arcp\Adapter\Symfony;

final class ArcpBundle extends \Symfony\Component\HttpKernel\Bundle\Bundle {
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void;
}

#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('arcp.agent')]
interface AgentMarker {}

final class ArcpServeCommand extends \Symfony\Component\Console\Command\Command { /* … */ }
```

---

## 5. `arcp/otel`

### Why this adapter

Mirrors `@arcp/middleware-otel` (`../typescript-sdk/packages/middleware/otel/src/index.ts`). The OpenTelemetry PHP API (`open-telemetry/api`, picked in Phase 03's seed) is autoloaded the same way as the JS `@opentelemetry/api`: the consumer wires the SDK + exporter, this package only emits spans. This rules out shipping a runtime exporter — exporter choice (`otlp`, `zipkin`, `jaeger`) is the consumer's call, mirroring the TS division.

### Span / attribute parity with TS

Span names mirror TS exactly (`index.ts` lines 73, 107):

- `arcp.send <type>` for outbound envelopes (`SpanKind::PRODUCER`).
- `arcp.recv <type>` for inbound envelopes (`SpanKind::CONSUMER`).

Attribute names mirror TS exactly (`index.ts` lines 144–177):

| TS key | PHP emit site | Source |
| -------- | -------- | ----- |
| `arcp.direction` | every span | `in` / `out` |
| `arcp.type` | envelope decode | `Envelope::$type->value` |
| `arcp.id` | envelope decode | `Envelope::$id` |
| `arcp.session_id` | envelope decode | `Envelope::$sessionId` |
| `arcp.job_id` | envelope decode | `Envelope::$jobId` |
| `arcp.trace_id` | envelope decode | `Envelope::$traceId` (spec §11 32-hex) |
| `arcp.event_seq` | envelope decode | `Envelope::$eventSeq` |
| `arcp.agent` | `job.submit` / `job.accepted` payload | resolves `name@version` |
| `arcp.lease.capabilities` | `lease` / `lease_request` keys, comma-joined | sorted for stability |
| `arcp.lease.expires_at` | §9.5 `lease_constraints.expires_at` | ISO-8601 UTC `Z` |
| `arcp.budget.remaining` | §9.6 `budget` object | `json_encode($budget, JSON_THROW_ON_ERROR)` |

Last two are the v1.1-specific additions called out in `01-spec-delta.md` §1 row §11.

### Traceparent extraction (`session.hello` / initial handshake)

The TS implementation propagates W3C trace context through `envelope.extensions["x-vendor.opentelemetry.tracecontext"]` (`index.ts` line 48 — the literal constant, ignore the docblock's shorthand). The PHP adapter does the same: on outbound `send`, inject the active `TextMapPropagator` carrier into the envelope's `extensions` map under the same key; on inbound frame, extract from the same key and start the receive span as a child. For the **initial WS upgrade** specifically, the `traceparent` HTTP header from the handshake `Request` is extracted once and stashed on the session so the `session.hello` recv-span continues that trace — this matches the brief's "traceparent header extraction on session.hello (or initial WS handshake)" requirement. Subsequent frames carry context inside the envelope, not in HTTP headers (there are no more HTTP headers after the WS upgrade completes).

### Host / DNS-rebind check

Not applicable at the OTEL middleware layer — that check lives in the host adapters (§1–§4). OTEL only observes; it does not accept connections.

### Public API sketch

```php
namespace Arcp\Otel;

final class TracingTransport implements \Arcp\Transport\Transport
{
    public function __construct(
        private \Arcp\Transport\Transport $inner,
        private \OpenTelemetry\API\Trace\TracerInterface $tracer,
        private \OpenTelemetry\API\Trace\Propagation\TextMapPropagatorInterface $propagator = null,
    ) {}

    public function send(\Arcp\Envelope\Envelope $envelope): void;
    public function onFrame(callable $handler): void;
    public function close(?string $reason = null): void;
}

function withTracing(
    \Arcp\Transport\Transport $inner,
    \OpenTelemetry\API\Trace\TracerInterface $tracer,
): \Arcp\Transport\Transport;
```

The `withTracing` free function mirrors the TS export name (`index.ts` line 57) so docs and code samples cross-translate without renaming.

---

## 6. Adapters explicitly rejected

| Rejected | Single-sentence reason |
| -------- | ---------------------- |
| `arcp/slim` | Slim 4 consumes PSR-15 natively — covered by `arcp/psr15` with zero glue; a Slim-named package would be a vanity wrapper. |
| `arcp/mezzio` | Mezzio is a PSR-15 application runner — covered by `arcp/psr15`; no additional integration surface exists. |
| `arcp/hono`, `arcp/bun`, `arcp/fastify` | These are JS/Bun runtimes; pretending to ship a PHP adapter for them would be dishonest. |
| `arcp/express` | Express is a Node HTTP framework; the TS `@arcp/middleware-express` package has no PHP counterpart because PHP does not run inside Node. |
| `arcp/node` | The TS `@arcp/middleware-node` adapter targets `node:http.Server`; the PHP analogue is `arcp/amphp-server`, which already exists in this plan. |
| `arcp/ratchet` (`cboden/ratchet`) | `cboden/ratchet` 0.4.x has been effectively unmaintained for years (last meaningful release predates PHP 8.0 idioms, and `react/socket` underlies it with no Fiber story); Phase 03 also rejected `ratchet/pawl` for the client side, so the server side aligns. |
| Swoole / OpenSwoole-only adapter | Swoole is a PECL extension with its own coroutine scheduler that conflicts with `revolt/event-loop`; embedding Swoole as the WS server forks the concurrency story for marginal throughput gain and is left to a separate `arcp/swoole-server` package outside the v1.1 milestone. |

---

## 7. Cross-cutting checklist

Every adapter above MUST:

1. Take `\Arcp\Runtime\Server` (or `\Arcp\Client\Client` for client-side adapters) as a constructor argument — no `new` of runtime internals inside the adapter.
2. Accept a `\Psr\Log\LoggerInterface` (PSR-3) for diagnostics; default to `\Psr\Log\NullLogger`.
3. Validate `Host` header against an explicit allowlist; default deny.
4. Negotiate `Sec-WebSocket-Protocol: arcp.v1` on every WS upgrade; reject mismatches with 400.
5. Surface the OTEL extension key `x-vendor.opentelemetry.tracecontext` as a constant (not a string literal) so a future v1.2 vendor-namespace change touches one file.
6. Run under PHP 8.3+ (`declare(strict_types=1);` everywhere, `final readonly` value objects, `match` expressions for type dispatch).
