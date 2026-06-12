# Job events (§8)

Job events are emitted through `JobContext` and logged as envelopes with
`job_id` and `trace_id` metadata.

## Event kinds (§8.2)

Job events ride as `job.event` envelopes whose payload is
`{kind, ts, body}`. The §8.2 kinds emitted by `JobContext` are
`progress`, `log`, `metric`, `status`, and `result_chunk`; vendor
extension kinds stay under the `x-` rule.

## Emitting from a tool

```php
$ctx->reportProgress(10, total: 100, units: 'files', message: 'started');
$ctx->emitLog('info', 'working');
$ctx->emitMetric('tokens.used', 120, 'tokens');
```

## Receiving on the client

Use `subscribe()` for live events (§7.6):

```php
$client->subscribe($jobId, fn (Envelope $env) => null);
```

## Sequence numbers (§8.3)

Sequenced job messages (`job.event`, `job.result`, terminal
`job.error`) carry the session-scoped, monotonically increasing
`event_seq`; §6.3 resume replays buffered envelopes with
`event_seq > last_event_seq`.

## Progress events (v1.1, §8.2.1)

Progress rides as a `job.event` of kind `progress` with body `{current, total?, units?, message?}` (§8.2.1).

## Result streaming (v1.1, §8.4)

```php
$resultId = $ctx->emitResultChunk('hello, ');
$ctx->emitResultChunk('world', more: false);
return null; // the terminal job.result carries result_id, not an inline result
```

The runtime mints the stable `result_id` on the first chunk; the
client buffers chunks in `ARCPClient::$resultChunks` and assembles by
`result_id` once the final chunk (`more: false`) arrives.

## Vendor extension events

Use `EventEmit` for structured custom events and extension message types
for `arcpx.*` custom envelopes.

## Lease enforcement on emission

`cost.*` metrics participate in `cost.budget` enforcement.

## Back-pressure interaction

Keep subscriber callbacks short and do heavy work outside the read loop.

## Runnable examples

See `samples/result-chunk/`, `samples/subscribe/`, and
`samples/reasoning-streams/`.
