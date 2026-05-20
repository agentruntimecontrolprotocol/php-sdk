# Delegation (§10)

The PHP SDK includes `agent.delegate` and `agent.handoff` message types
and samples. Full cross-runtime delegation policy remains host-defined.

## Why one verb

Delegation keeps parent and child work linked by job ids, trace ids, and
lease constraints.

## Parent side

Build and send `AgentDelegate` envelopes when forwarding work to another
runtime.

## Child agent

Child runtimes should expose ordinary tools and return ordinary job
events/results.

## Subset validation

Child leases and budgets must not exceed parent scope. Use
`CostBudget::containsSubset()` for budget checks.

## Client side

Clients observe delegation through job events and subscriptions.

## Trace propagation

Forward `trace_id` to preserve causality across runtimes.

## Cancellation cascade

When a parent job is cancelled, host code should cancel delegated child
jobs.

## Idempotency

Reuse idempotency keys when retrying the same delegated operation.

## Runnable example

See `samples/delegation/`, `samples/handoff/`, and
`samples/reasoning_streams/`.
