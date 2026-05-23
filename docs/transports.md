# Transports

The transport interface is `Arcp\Transport\Transport`:

```php
interface Transport
{
    public function send(Envelope $env, ?Cancellation $cancellation = null): void;
    public function receive(?Cancellation $cancellation = null): ?Envelope;
    public function close(): void;
    public function isClosed(): bool;
}
```

## WebSocket

Use `Arcp\Transport\WebSocketTransport` for long-lived client/runtime
connections across process or machine boundaries.

### Server

The CLI starts a WebSocket runtime:

```sh
bin/arcp serve --host 127.0.0.1 --port 8765
```

### Client

Create a WebSocket transport and pass it to `ARCPClient`.

### When to use

- Browser or remote clients.
- Multi-process runtime hosting.
- Durable sessions across network boundaries.

### DNS-rebind protection

If exposing a runtime from a web app, validate `Host` / origin headers
at the HTTP/WebSocket layer before constructing the ARCP transport.

## stdio

`StdioTransport` is newline-delimited JSON over process pipes.

### As a subprocess

Use it when the runtime is a child process and the parent owns process
lifetime.

### When to use

- CLI agents.
- Sandboxed worker processes.
- Local supervisor/worker topologies.

### Limitations

stdio has no native multiplexing. One ARCP session per process pair is
the simplest model.

## In-memory

`MemoryTransport::pair()` is for tests and demos. It avoids network I/O
and keeps both sides in one PHP process.

### When to use

- PHPUnit integration tests.
- Documentation snippets.
- Local protocol experiments.

### Caveats

It is not a production transport. It does not exercise framing,
network-level backpressure, TLS, or process failure.

## Writing your own

Implement `Transport`, then pass it to `ARCPClient` or
`ARCPRuntime::serve()`. Keep serialization at the boundary and make
closed-transport behavior explicit.

## With OTel tracing

Wrap `send()` and `receive()` to create spans around envelope I/O. See
[Observability](./guides/observability.md).
