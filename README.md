<h3 align="center">ARCP PHP SDK</h3>

<p align="center"><strong>PHP SDK for the Agent Runtime Control Protocol (ARCP) — submit, observe, and control long-running agent jobs from PHP.</strong></p>

<p align="center">
  <a href="https://packagist.org/packages/arcp/sdk"><img alt="Packagist" src="https://img.shields.io/packagist/v/arcp/sdk.svg"></a>
  <a href="https://github.com/agentruntimecontrolprotocol/php-sdk/actions/workflows/test.yml"><img alt="CI" src="https://github.com/agentruntimecontrolprotocol/php-sdk/actions/workflows/test.yml/badge.svg"></a>
  <a href="https://packagist.org/packages/arcp/sdk"><img alt="PHP version" src="https://img.shields.io/packagist/php-v/arcp/sdk.svg"></a>
  <a href="https://github.com/agentruntimecontrolprotocol/spec/blob/main/docs/draft-arcp-1.1.md"><img alt="ARCP" src="https://img.shields.io/badge/ARCP-v1.1%20draft-blue"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-lightgrey"></a>
  <a href="https://codecov.io/gh/agentruntimecontrolprotocol/php-sdk"><img alt="Codecov" src="https://codecov.io/gh/agentruntimecontrolprotocol/php-sdk/branch/main/graph/badge.svg"></a>
</p>

<p align="center">
  <a href="https://github.com/agentruntimecontrolprotocol/spec/blob/main/docs/draft-arcp-1.1.md">Specification</a> ·
  <a href="#concepts">Concepts</a> ·
  <a href="#installation">Install</a> ·
  <a href="#quick-start">Quick start</a> ·
  <a href="docs/">Guides</a> ·
  <a href="docs/">API reference</a>
</p>

---

`arcp/sdk` is the PHP reference implementation of [ARCP](https://github.com/agentruntimecontrolprotocol/spec/blob/main/docs/draft-arcp-1.1.md), the Agent Runtime Control Protocol. It covers both sides of the wire — `Arcp\Client\ARCPClient` for submitting and observing jobs, `Arcp\Runtime\ARCPRuntime` for hosting agents — so either side can talk to any conformant peer in any language without hand-rolling the envelope, sequencing, or lease enforcement.

ARCP itself is a transport-agnostic wire protocol for long-running AI agent jobs. It owns the parts of agent infrastructure that don't change between products — sessions, durable event streams, capability leases, budgets, resume — and stays out of the parts that do. ARCP wraps the agent function; it does not define how agents are built, how tools are exposed (that's MCP), or how telemetry is exported (that's OpenTelemetry).

## Installation

Requires PHP 8.4 or newer and Composer 2.x. The SDK ships as a single Composer package; the `bin/arcp` CLI is registered automatically so `vendor/bin/arcp` is available in any project that pulls it in.

```sh
composer require arcp/sdk
```

The `pdo_sqlite`, `mbstring`, and `json` extensions are required (all bundled with most PHP distributions). The runtime uses [Amp v3](https://amphp.org/) + fibers; no extra extension is needed for async.

## Quick start

Connect to a runtime, submit a job, stream its events to completion:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Amp\Websocket\Client\WebsocketHandshake;
use function Amp\Websocket\Client\connect;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Execution\JobProgress;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Transport\WebSocketTransport;

$serializer = new EnvelopeSerializer(MessageCatalog::create());
$transport = new WebSocketTransport(
    connect(new WebsocketHandshake('wss://runtime.example.com/arcp')),
    $serializer,
);

$client = new ARCPClient($transport);
$client->open(
    Auth::bearer((string) getenv('ARCP_TOKEN')),
    new PeerInfo('quickstart', '1.0.0'),
    new Capabilities(streaming: true, subscriptions: true),
);

$client->subscribe(
    ['types' => ['job.progress', 'log', 'metric']],
    static function (Envelope $env): void {
        if ($env->payload instanceof JobProgress) {
            printf("[progress %d%%] %s\n", $env->payload->percent, $env->payload->message ?? '');
        }
    },
);

$result = $client->invokeTool('data-analyzer', ['dataset' => 's3://example/sales.csv']);
printf("final: %s\n", json_encode($result->value));

$client->close();
```

This is the whole shape of the SDK: open a session, submit work, consume an ordered event stream, get a terminal result or error. Everything below is detail on those four moves.

## Concepts

ARCP organizes everything around four concerns — **identity**, **durability**, **authority**, and **observability** — expressed through five core objects:

- **Session** — a connection between a client and a runtime. A session carries identity (a bearer token), negotiates a feature set in a `hello`/`welcome` handshake, and is *resumable*: if the transport drops, you reconnect with a resume token and the runtime replays buffered events. Jobs outlive the session that started them. See [§6](https://github.com/agentruntimecontrolprotocol/spec/blob/main/docs/draft-arcp-1.1.md).
- **Job** — one unit of agent work submitted into a session. A job has an identity, an optional idempotency key, a resolved agent version, and a lifecycle that ends in exactly one terminal state: `success`, `error`, `cancelled`, or `timed_out`. See [§7](https://github.com/agentruntimecontrolprotocol/spec/blob/main/docs/draft-arcp-1.1.md).
- **Event** — the ordered, session-scoped stream a job emits: logs, thoughts, tool calls and results, status, metrics, artifact references, progress, and streamed result chunks. Events carry strictly monotonic sequence numbers so the stream survives reconnects gap-free. See [§8](https://github.com/agentruntimecontrolprotocol/spec/blob/main/docs/draft-arcp-1.1.md).
- **Lease** — the authority a job runs under, expressed as capability grants (`fs.read`, `fs.write`, `net.fetch`, `tool.call`, `agent.delegate`, `cost.budget`, `model.use`). The runtime enforces the lease at every operation boundary; a job can never act outside it. Leases may carry a budget and an expiry, and may be subset and handed to sub-agents via delegation. See [§9](https://github.com/agentruntimecontrolprotocol/spec/blob/main/docs/draft-arcp-1.1.md).
- **Subscription** — read-only attachment to a job started elsewhere (e.g. a dashboard watching a job a CLI submitted). A subscriber observes the live event stream but cannot cancel or mutate the job. Distinct from *resume*, which continues the original session and carries cancel authority. See [§7.6](https://github.com/agentruntimecontrolprotocol/spec/blob/main/docs/draft-arcp-1.1.md).

The SDK models each of these as first-class objects; the rest of this README shows how.

## Guides

### Sessions and resume

Open a session, negotiate features, and reconnect transparently after a transport drop using the resume token — jobs keep running server-side while you're gone.

```php
use Amp\Websocket\Client\WebsocketHandshake;
use function Amp\Websocket\Client\connect;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Ids\MessageId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Control\Resume;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Transport\WebSocketTransport;

$serializer = new EnvelopeSerializer(MessageCatalog::create());
$openTransport = static fn (): WebSocketTransport => new WebSocketTransport(
    connect(new WebsocketHandshake('wss://runtime.example.com/arcp')),
    $serializer,
);

$client = new ARCPClient($openTransport());
$accepted = $client->open(
    Auth::bearer((string) getenv('ARCP_TOKEN')),
    new PeerInfo('resumable', '1.0.0'),
    new Capabilities(streaming: true, durableJobs: true),
);
$sessionId = $accepted->sessionId;
$lastMessageId = null;

// ... transport drops ...

$resumed = new ARCPClient($openTransport());
$resumed->open(Auth::bearer((string) getenv('ARCP_TOKEN')), new PeerInfo('resumable', '1.0.0'), new Capabilities(streaming: true, durableJobs: true));
$resumed->session->transport->send(new Envelope(
    id: MessageId::random(),
    payload: new Resume(afterMessageId: (string) $lastMessageId, includeOpenStreams: true),
    timestamp: $resumed->clock->now(),
    sessionId: $sessionId,
));
// The runtime replays every envelope with id > $lastMessageId, then resumes live streaming.
```

### Submitting jobs

Submit a job with an agent (optionally version-pinned as `name@version`), an input, and an optional lease request, idempotency key, and runtime limit.

```php
use Arcp\Ids\IdempotencyKey;

$result = $client->invokeTool(
    tool: 'weekly-report@2.1.0',
    arguments: [
        'week' => '2026-W19',
        'lease' => ['net.fetch' => ['s3://reports/**']],
        'expires_at' => (new DateTimeImmutable('+60 seconds'))->format(DateTimeInterface::RFC3339_EXTENDED),
    ],
    deadlineSeconds: 300.0,
    idempotencyKey: new IdempotencyKey('weekly-report-2026-W19'),
);

printf("resolved value = %s\n", json_encode($result->value));
```

### Consuming events

Iterate the ordered event stream — `log`, `metric`, `event.emit`, `tool.invoke`, `tool.result`, `job.progress`, `job.result_chunk`, `artifact.ref` — and optionally acknowledge progress so the runtime can release buffered events early.

```php
use Arcp\Envelope\Envelope;
use Arcp\Messages\Execution\JobProgress;
use Arcp\Messages\Execution\ResultChunk;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Messages\Telemetry\LogEvent;
use Arcp\Messages\Telemetry\MetricEvent;

$client->subscribe(
    ['session_id' => [(string) $client->session->sessionId]],
    static function (Envelope $env) use ($client): void {
        match (true) {
            $env->payload instanceof LogEvent      => printf("[log] %s\n", $env->payload->message),
            $env->payload instanceof MetricEvent   => printf("[metric] %s=%s %s\n", $env->payload->name, $env->payload->value, $env->payload->unit),
            $env->payload instanceof JobProgress   => printf("[progress %d%%] %s\n", $env->payload->percent, $env->payload->message ?? ''),
            $env->payload instanceof ResultChunk   => $client->resultChunks->push($env->payload),
            $env->payload instanceof EventEmit     => printf("[event] %s\n", $env->payload->eventType),
            default                                => null,
        };
    },
);
```

### Leases and budgets

Request capabilities, a budget, and an expiry; read budget-remaining metrics as they arrive; handle the runtime's enforcement decisions.

```php
use Arcp\Envelope\Envelope;
use Arcp\Errors\BudgetExhaustedException;
use Arcp\Messages\Telemetry\MetricEvent;

$client->subscribe(
    ['types' => ['metric']],
    static function (Envelope $env): void {
        if ($env->payload instanceof MetricEvent && $env->payload->name === 'cost.budget.remaining') {
            printf("budget remaining: %.2f %s\n", $env->payload->value, $env->payload->unit);
        }
    },
);

try {
    $client->invokeTool('web-research', [
        'iterations' => 8,
        'per_call_usd' => 0.30,
        'lease' => [
            'tool.call'   => ['search.*', 'fetch.*'],
            'cost.budget' => ['USD:1.00'],
            'model.use'   => ['anthropic/claude-*'],
        ],
        'expires_at' => (new DateTimeImmutable('+10 minutes'))->format(DateTimeInterface::RFC3339_EXTENDED),
    ]);
} catch (BudgetExhaustedException $e) {
    // BUDGET_EXHAUSTED / LEASE_EXPIRED are never retryable: resubmit with a fresh lease.
    fwrite(STDERR, "job ended: {$e->code()->value}\n");
}
```

### Subscribing to jobs

Attach read-only to a job submitted elsewhere and observe its live stream (with optional history replay) without cancel authority.

```php
use Arcp\Envelope\Envelope;

$listing = $observer->listJobs(['status' => ['running']], limit: 10);
$first = $listing->jobs[0] ?? null;
if ($first === null) {
    return;
}

$subscriptionId = $observer->subscribe(
    [
        'job_id' => [$first['job_id']],
        'since_message_id' => null,           // omit to start live; set for backfill
    ],
    static function (Envelope $env): void {
        printf("[seq=%s] %s\n", (string) $env->id, $env->type());
    },
);

// ... later ...
$observer->unsubscribe($subscriptionId);
```

### Error handling

Catch the typed error taxonomy and respect the `retryable` flag — `LEASE_EXPIRED` and `BUDGET_EXHAUSTED` are never retryable; a naive retry fails identically.

```php
use Arcp\Errors\ARCPException;
use Arcp\Errors\BudgetExhaustedException;
use Arcp\Errors\ErrorCode;
use Arcp\Errors\LeaseExpiredException;

try {
    $client->invokeTool('flaky');
} catch (BudgetExhaustedException | LeaseExpiredException $e) {
    throw $e; // resubmit with a fresh lease / budget instead
} catch (ARCPException $e) {
    if ($e->isRetryable()) {
        // safe to retry with backoff (e.g. UNAVAILABLE, DEADLINE_EXCEEDED, INTERNAL)
        return;
    }
    fprintf(STDERR, "fatal: %s (%s)\n", $e->code()->value, $e->getMessage());
    throw $e;
}
```

## Feature support

ARCP features this SDK negotiates during the `hello`/`welcome` handshake:

| Feature flag | Status |
|---|---|
| `heartbeat` | Supported |
| `ack` | Partial |
| `list_jobs` | Supported |
| `subscribe` | Supported |
| `lease_expires_at` | Supported |
| `cost.budget` | Supported |
| `model.use` | Supported |
| `provisioned_credentials` | Supported |
| `progress` | Supported |
| `result_chunk` | Supported |
| `agent_versions` | Supported |

## Transport

ARCP is transport-agnostic. This SDK ships a WebSocket transport (default), an stdio transport for in-process child runtimes, and an in-memory transport for tests. WebSocket is the default for networked runtimes; stdio is used for in-process child runtimes. Select one by constructing the corresponding `Arcp\Transport\Transport` implementation (`new WebSocketTransport($connection, $serializer)`, `new StdioTransport(...)`, `MemoryTransport::pair()`) and passing it to `new ARCPClient($transport)`; the bundled `bin/arcp serve --host H --port P` exposes a WebSocket runtime that mirrors the same protocol on the wire.

## API reference

Full API reference — every type, method, and event payload — is in [`docs/`](docs/).

## Versioning and compatibility

This SDK speaks **ARCP v1.1 (draft)**. The SDK follows semantic versioning independently of the protocol; the protocol version it negotiates is shown above and in `session.hello`. A runtime advertising a different ARCP MAJOR is not guaranteed compatible. Feature mismatches degrade gracefully: the effective feature set is the intersection of what the client and runtime advertise, and the SDK will not use a feature outside it.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Protocol questions and proposed changes belong in the [spec repository](https://github.com/agentruntimecontrolprotocol/spec); SDK bugs and feature requests belong here.

## License

Apache-2.0 — see [`LICENSE`](LICENSE).
