# Observability (§11)

The PHP SDK exposes logs, metrics, trace ids, and spans as protocol
messages.

## Trace propagation

Pass `TraceId` to `invokeTool()` or rely on the client to generate one.
Runtime emissions preserve the trace id.

## Setup

Inject a PSR-3 logger into `ARCPClient` or `ARCPRuntime`.

## Span shape

Use `TraceSpan` for explicit spans and envelope metadata for trace ids.

## Per-job spans

Emit spans from tools around external calls or long-running phases.

## Delegation cascades

Forward `trace_id` when delegating to preserve the span tree.

## Without OTel

You can still observe logs and metrics through subscriptions and the
event log.

## Heartbeats vs spans

Heartbeats prove liveness; spans describe work.

## Per-session log binding

Log envelope ids, session ids, job ids, and trace ids in host logs.

## Sampling

Sampling is host-defined. Do not sample away protocol errors or terminal
job states.

## Runnable example

See `samples/capability-negotiation/` and `samples/reasoning-streams/`.
