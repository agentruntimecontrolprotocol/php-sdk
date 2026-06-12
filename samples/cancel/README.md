# cancel

Two scenarios that exercise the §10.4–§10.5 control surface that
distinguishes ARCP from "agent over plain HTTP":

- `job.cancel`: cooperative termination (§7.4).
- `interrupt`: pause the job and keep the job in a blocked state
  without terminating it.

## Before ARCP

Cancellation usually means closing the socket or trying to kill the
process. The agent's tool was already mid-network call, so it
either completes anyway (silent waste of money) or leaves a
half-applied side effect. There's no notion of "stop and ask"; the
only knob is "stop".

## With ARCP

```php
// Stop the job; the runtime drives it to a clean checkpoint
// inside `deadline_ms` before terminating.
$client->cancelJob($jobId, 'user_aborted', 5_000);  // cancel.accepted
$terminal = awaitTerminal($client, $jobId);          // job.cancelled

// Or: pause the job and resume once the caller has resolved the block.
interruptJob($client, $jobId, 'Pause and ask before touching prod.');
```

## ARCP primitives

- `job.cancel` cooperative contract — §7.4 (`job.cancelled` ack /
  `cancel.refused`, `deadline_ms`, escalation to `ABORTED`).
- `interrupt` (distinct from cancel) — §10.5; leaves the job in
  `blocked` so a caller can decide whether to resume or cancel.
- `capabilities.interrupt: false` fallback to `job.cancel` (advertised
  per §10.5; clients that find `interrupt: false` on a peer fall
  through to `job.cancel`).

## File tour

- `main.php` — two scenarios driven by `$argv[1]` (`cancel` or
  `interrupt`).

## Variations

- Send `job.cancel` for an unknown `job_id` to
  terminate just one stream — terminal is a `stream.error` with
  `code: CANCELLED` (§10.4).
- Race many peers, cancel the slowest once N succeed.
