# heartbeat

Dynamic peer-runtime federation. Workers register, take work via
`agent.delegate`, send heartbeats, and deregister cleanly. Heartbeat
loss reroutes the in-flight task to another worker — deduped by
`idempotency_key`.

## Before ARCP

Static worker pools with bespoke RPCs. The supervisor's "is this
worker alive?" answer comes from a TCP keepalive (lies during GC) or
a custom heartbeat that re-dispatch logic doesn't actually trust —
so re-dispatch either fires too eagerly (duplicate execution) or not
at all (stuck pipeline).

## With ARCP

```php
function reaper(ARCPClient $sup, Roster $roster, array &$jobs): void
{
    while (true) {
        delay(HEARTBEAT_INTERVAL_SECONDS);
        foreach ($roster->workers as $w) {
            if (stale($w)) {
                if (($jid = $w->inFlightJob) !== null && isset($jobs[$jid])) {
                    dispatch($sup, $jobs[$jid], $roster, $jobs); // same key
                }
                unset($roster->workers[$w->workerId]);
            }
        }
    }
}
```

`idempotency_key` makes re-dispatch safe: a worker that survived the
network blip will see the duplicate `agent.delegate` and dedupe.

## ARCP primitives

- Capability negotiation (per-role extension) — RFC §7, §21.
- `agent.delegate` — §14.
- Job lifecycle (accepted → started → heartbeat → terminal) — §10.
- Heartbeat loss recovery — §10.3 (`heartbeat_recovery: "block"`).
- `idempotency_key` for safe re-dispatch — §6.4.
- Trust levels — §15.3.

## File tour

- `main.php` — boots supervisor + small worker pool in-process.
  `dispatch`, `reaper`, `execute` all live here.
- `work.php` — `doWork()` payload-handler stub.

## Variations

- Priority queues by tagging tasks with envelope `priority`.
- Per-worker quota tracked via `tokens.used` metrics emitted from
  worker sessions (RFC §17.3.1).
- Replace the in-process workers with separate processes on real
  hosts; the protocol shape doesn't change.
