# Retries

ARCP does not enforce a retry policy at the wire level. The SDK gives
you the signal — `ARCPExceptionInterface::isRetryable()` — and the
correlation id (the envelope `id`) so callers can build a policy
appropriate to their workload.

## When to retry

Retry on `isRetryable() === true`. The canonical codes that default to
retryable (RFC §18.3) are:

- `ABORTED`
- `DEADLINE_EXCEEDED`
- `INTERNAL`
- `RESOURCE_EXHAUSTED` (and its `RATE_LIMITED` alias)
- `UNAVAILABLE` (includes `TransportClosedException`)

The sender MAY override the default on a per-exception basis by
setting `$retryable` explicitly. **Always trust `isRetryable()`** over
the code default — the runtime's signal is authoritative for that
specific failure.

## Backoff

Use exponential backoff with jitter. Keep the maximum total wall-clock
under the caller's deadline.

```php
$attempt = 0;
while (true) {
    try {
        return $client->invokeTool($name, $args);
    } catch (\Arcp\Errors\ARCPExceptionInterface $e) {
        if (!$e->isRetryable() || ++$attempt > 5) {
            throw $e;
        }
        $sleepMs = min(30_000, (int) (250 * 2 ** $attempt));
        $jitterMs = random_int(0, (int) ($sleepMs / 4));
        \Amp\delay(($sleepMs + $jitterMs) / 1000);
    }
}
```

## Idempotency

For mutating operations, set an `idempotency_key` on the request
envelope. The runtime caches `(principal, idempotency_key) →
result_message_id` for the negotiated retention horizon (RFC §6.4)
and short-circuits retries to the same outcome.

```php
$idem = new IdempotencyKey('order-' . $order->id);
$result = $client->invokeTool('orders.create', $args, idempotencyKey: $idem);
```

If the retry races with the original request still in flight, the SDK
returns the in-flight job's eventual outcome rather than starting a
second tool execution.

## Heartbeat-driven retry

If a long-running job's heartbeat lapses, the runtime raises
`HeartbeatLostException` (non-retryable by default — the work was
abandoned mid-flight). The caller decides whether to resubmit (with a
fresh idempotency key) or treat the work as failed.

## Things not to do

- Don't retry on non-retryable codes — they're not transient.
  `PERMISSION_DENIED` won't change without an operator action.
- Don't retry without backoff. A tight loop turns a transient blip
  into a synthetic outage.
- Don't retry beyond the caller's deadline. Keep the budget visible.
