# Architecture

The PHP SDK is a single Composer package, `arcp/sdk`, with namespaces
that map to the same layers as the TypeScript SDK packages.

## Layering

| Layer | PHP namespace |
| --- | --- |
| Wire/core | `Arcp\Envelope`, `Arcp\Messages`, `Arcp\Errors`, `Arcp\Ids` |
| Client | `Arcp\Client` |
| Runtime | `Arcp\Runtime`, `Arcp\Internal\Runtime` |
| Transports | `Arcp\Transport` |
| Persistence | `Arcp\Store` |
| Time | `Arcp\Clock` |
| Extensions | `Arcp\Extensions` |

The public surface is intentionally small: `ARCPClient`, `ARCPRuntime`,
the message DTOs, transports, typed errors, and IDs. Internal dispatch
collaborators live under `Arcp\Internal`.

## Core

`Arcp\Envelope\Envelope` is the top-level wire object. Payload
polymorphism is handled by `MessageTypeRegistry`; the default registry is
created by `MessageCatalog::create()`.

Serialization is handled by `Arcp\Json\EnvelopeSerializer`, which turns
envelopes into protocol JSON and back without reflection.

## Client

`Arcp\Client\ARCPClient` owns:

- session handshake,
- the background read loop,
- correlated response routing,
- subscription callbacks,
- helper APIs such as `invokeTool()`, `listJobs()`, `subscribe()`,
  `cancelJob()`, `putArtifact()`, and `fetchArtifact()`.

## Runtime

`Arcp\Runtime\ARCPRuntime` owns:

- registered tools and versioned tool resolution,
- session negotiation,
- job lifecycle,
- permissions and leases,
- artifacts,
- subscriptions,
- event logging.

Tool handlers implement `Arcp\Runtime\ToolHandler` and receive a
`JobContext` for progress, streams, metrics, permissions, artifacts, and
result chunks.

## CLI

The aggregate package exposes `bin/arcp`, implemented with Symfony
Console under `Arcp\Cli`.

## Middleware

There are no separate PHP middleware packages. PHP hosts should adapt
`Arcp\Transport\Transport` to their framework or process model.

## Wire format

All messages are PHP value objects extending `MessageType`. Unknown core
types are rejected as `UNIMPLEMENTED`; extension handling is delegated to
`ExtensionRegistry`.

## Where to read the code

- `src/Client/ARCPClient.php`
- `src/Runtime/ARCPRuntime.php`
- `src/Envelope/MessageCatalog.php`
- `src/Internal/Runtime/Dispatcher.php`
- `src/Internal/Runtime/ToolInvocationHandler.php`
