# `arcp/arcp` — ARCP PHP SDK

PHP 8.4 reference implementation of the [Agent Runtime Control Protocol
(ARCP) v1.0](RFC-0001-v2.md).

> **Status:** v0.1 in active development. The Phase 0 plan is in
> [PLAN.md](PLAN.md); the conformance matrix is in [CONFORMANCE.md](CONFORMANCE.md).

## Quick start

```sh
composer install

# Run tests
vendor/bin/phpunit --testdox

# Run static analysis
vendor/bin/phpstan analyze
vendor/bin/psalm --no-cache

# Run formatting check
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Requirements

- PHP **8.4** or newer.
- Composer 2.x.
- The `pdo_sqlite`, `mbstring`, and `json` extensions (bundled with
  most PHP distributions).

## Architecture

```
+-----------------------------+
| Capability Layer            |
| (MCP Compatible)            |
+-----------------------------+
+-----------------------------+
| ARCP Runtime Layer          |
| - Identity & Sessions       |
| - Streams                   |
| - Jobs                      |
| - Subscriptions             |
| - Events                    |
| - Permissions & Leases      |
| - Artifacts                 |
| - Tracing & Metrics         |
+-----------------------------+
+-----------------------------+
| Transport Layer             |
| WebSocket / stdio (mandatory)|
+-----------------------------+
```

The runtime is `Arcp\Runtime\ARCPRuntime`; the client is
`Arcp\Client\ARCPClient`. Both are async (Amp v3 + fibers) and both take a
`Arcp\Transport\Transport` instance, which is one of `MemoryTransport`,
`WebSocketTransport`, or `StdioTransport`.

## RFC mapping

| RFC section | Implementation |
| --- | --- |
| §6.1 Envelope | `src/Envelope/Envelope.php` |
| §6.2 Message types | `src/Messages/<group>/*.php` + `src/Envelope/MessageTypeRegistry.php` |
| §7 Capability negotiation | `src/Runtime/Session.php` |
| §8 Authentication | `src/Auth/*` + `src/Runtime/Session.php` |
| §10 Jobs | `src/Runtime/JobManager.php` |
| §11 Streaming | `src/Runtime/StreamManager.php` |
| §12 Human-in-the-loop | `src/Runtime/PendingRegistry.php` + `src/Client/Handlers/*` |
| §13 Subscriptions | `src/Runtime/SubscriptionManager.php` |
| §15 Permissions & leases | `src/Runtime/LeaseManager.php` |
| §16 Artifacts | `src/Runtime/ArtifactStore.php` |
| §17 Observability | `src/Trace/*` + `src/Messages/Telemetry/*` |
| §18 Errors | `src/Errors/*` |
| §19 Resume | `src/Store/EventLog.php` |
| §21 Extensions | `src/Extensions/*` |
| §22 Transports | `src/Transport/*` |

## What's not implemented in v0.1

See [PLAN.md §7](PLAN.md#7-out-of-scope-for-v01-explicit) for the full
list. Calls into out-of-scope surfaces throw `UnimplementedException`
with the relevant RFC section.

## License

Apache-2.0.
