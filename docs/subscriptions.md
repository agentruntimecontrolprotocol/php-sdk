# Subscriptions

ARCP subscriptions deliver events scoped to a session, job, stream, or
filter expression (RFC §13). The PHP SDK exposes the contract through
`ARCPClient::subscribe()` and `JobContext::emitEvent()`.

## Subscribing

```php
use Arcp\Ids\SubscriptionId;
use Arcp\Messages\Telemetry\EventEmit;

$subId = $client->subscribe(
    filter: ['types' => ['order.shipped']],
    onEvent: function (EventEmit $event): void {
        echo "shipped: " . ($event->attributes['order_id'] ?? '?') . "\n";
    },
);

// Later:
$client->unsubscribe($subId);
```

The `filter` array accepts the keys listed in RFC §13.2 — `session_id`,
`trace_id`, `job_id`, `stream_id`, `types`, `min_priority`. Omitting
all of them subscribes to the entire session.

## Backfill

The first envelope a subscriber receives is `subscription.accepted`,
followed by any retained events the filter matched (replay since the
caller's `replay_from`, if specified). The runtime emits
`subscription.backfill_complete` once history is drained — callers can
use that marker to switch from "catching up" to "live."

## Emitting events from tools

Inside a tool handler, `JobContext::emitEvent()` publishes to every
matching subscriber:

```php
public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
{
    $ctx->emitEvent(new EventEmit(
        type: 'order.shipped',
        attributes: ['order_id' => $arguments['id']],
    ));
    return new ShippedReceipt();
}
```

## Cross-session subscriptions

Subscribing to another session's events is rejected with
`PERMISSION_DENIED`. The runtime enforces session scoping by default;
deployments that need cross-session telemetry should publish into a
dedicated bus rather than relying on cross-session subscribes.

## Backpressure

If a subscriber's outbox grows beyond the configured threshold, the
runtime emits `BackpressureOverflowException` (`BACKPRESSURE_OVERFLOW`)
on the publisher side. Subscribers receive a `subscription.closed`
envelope so they can reconnect with a tighter filter or a smaller
replay window.

See `samples/subscriptions/` for end-to-end examples.
