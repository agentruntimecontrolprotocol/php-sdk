# Recipes

## Streaming progress

```php
$ctx->reportProgress(50, 'halfway');
```

Subscribe to `job.progress` or use the event log to inspect progress
after the fact.

## Crash-safe submission

Pass an `IdempotencyKey` to `ARCPClient::invokeTool()` so retries do not
re-execute a completed operation for the same principal.

## Retry with backoff

Catch `ARCPExceptionInterface`, retry only when `isRetryable()` is true,
and reuse the same idempotency key.

## Per-tenant runtime

Store the authenticated principal on `PeerInfo`; the runtime copies it
to `Session::$principal` and uses it for idempotency and job visibility.

## Custom auth verifier

Implement `AuthScheme`, register it in `AuthRouter`, and pass the router
to `ARCPRuntime`.

## Lease enforcement in a custom tool

Call `JobContext::requestPermission()` before side effects and store the
returned lease id with the local operation.

## In-process client + runtime for tests

Use `MemoryTransport::pair()` and `ARCPRuntime::serveAsync()`.

## Subprocess agent

Use `StdioTransport` over child process pipes and keep stdout reserved
for ARCP frames.

## Subscribing to a foreign job (v1.1)

Call `subscribe()` with a filter scoped to the same session or
principal. Cross-principal observation should be denied by host policy.

## Listing jobs (v1.1)

```php
$page = $client->listJobs(['agent' => 'planner', 'status' => ['running']], limit: 20);
```

Use `$page->nextCursor` to continue.

## Per-job log correlation

Use `JobContext::emitLog()`; emitted envelopes carry `job_id` and
`trace_id`.

## Vendor extension event

Use `EventEmit` for structured custom events and an `arcpx.*` namespace
for custom message types.

## Result streaming (v1.1)

```php
$ctx->emitResultChunk('res_report', 'part one');
$ctx->emitResultChunk('res_report', 'part two', more: false);
```

On the client, call `$client->resultChunks->assemble('res_report')`.
