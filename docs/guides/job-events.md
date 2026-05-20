# Job events (§8)

Job events are emitted through `JobContext` and logged as envelopes with
`job_id` and `trace_id` metadata.

## The eight kinds

PHP exposes typed messages for progress, heartbeats, streams, logs,
metrics, artifacts, and terminal job messages.

## Emitting from a tool

```php
$ctx->reportProgress(10, 'started');
$ctx->emitLog('info', 'working');
$ctx->emitMetric('tokens.used', 120, 'tokens');
```

## Receiving on the client

Use `subscribe()` for live events:

```php
$client->subscribe(['types' => ['log', 'metric']], fn (Envelope $env) => null);
```

## Sequence numbers (§8.3)

PHP currently uses message ids and event-log insertion order for replay.

## Progress events (v1.1, §8.2.1)

`JobProgress` carries `percent` and an optional message.

## Result streaming (v1.1, §8.4)

```php
$ctx->emitResultChunk('res_x', 'hello, ');
$ctx->emitResultChunk('res_x', 'world', more: false);
```

The client buffers chunks in `ARCPClient::$resultChunks`.

## Vendor extension events

Use `EventEmit` for structured custom events and extension message types
for `arcpx.*` custom envelopes.

## Lease enforcement on emission

`cost.*` metrics participate in `cost.budget` enforcement.

## Back-pressure interaction

Keep subscriber callbacks short and do heavy work outside the read loop.

## Runnable examples

See `samples/result_chunk/`, `samples/subscriptions/`, and
`samples/reasoning_streams/`.
