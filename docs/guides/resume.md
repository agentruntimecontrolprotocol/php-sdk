# Resume (§6.3)

Resume is event-log replay after a known message id.

## Mechanics

The runtime appends envelopes to `EventLog`. A resume request identifies
the last processed message id; the runtime replays later events.

## API

Use `Resume` envelopes directly for low-level flows. The integration
tests show the canonical behavior.

## Tracking `last_event_seq`

PHP currently tracks replay by message id, not by a separate event
sequence field.

## Replay guarantees

Replay order follows event-log insertion order.

## Window expiry

If the event log no longer contains the requested message id, the runtime
returns a data-loss style error.

## Auth invariants

Only resume sessions under the same authenticated principal.

## When jobs are pending across a resume

Host applications should decide whether jobs survive process restarts.
The default in-memory runtime is process-local.

## Idempotent submit + resume

Combine `IdempotencyKey` with event-log replay to avoid duplicate work.

## Runnable example

See `samples/resume/` and `tests/Integration/ResumeTest.php`.
