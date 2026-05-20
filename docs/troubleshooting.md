# Troubleshooting

## `UNAUTHENTICATED` on connect

Check the `AuthRouter` configured on the runtime. With no router,
anonymous sessions require `Auth::none()` and `Capabilities(anonymous:
true)`.

## `RESUME_WINDOW_EXPIRED`

The PHP event log can replay after a known message id. If the referenced
message is absent, resume fails with a data-loss style error.

## `INVALID_REQUEST` on resume

Verify that `after_message_id` or checkpoint fields refer to an event
the runtime knows how to replay.

## `PERMISSION_DENIED` from a tool

The tool likely requested a permission lease and the client-side
`PermissionHandler` denied it, or an existing lease did not match the
required scope.

## `LEASE_SUBSET_VIOLATION` on delegate

The PHP SDK currently exposes delegation envelopes and examples, but
full cross-runtime delegation policy is still host-defined.

## Job stuck in `accepted` or `running`

Make sure the tool handler returns, throws an `ARCPException`, or
cooperatively observes cancellation. Long-running handlers should emit
heartbeats or progress.

## Stdio transport breaks unexpectedly

Confirm each envelope is serialized as one newline-delimited JSON frame
and that the child process does not write non-ARCP text to stdout.

## Back-pressure stall

If the host transport blocks, drain the receive loop and avoid doing
long CPU work in callbacks.

## Memory growth on long sessions

Use `EventLog::fromFile()` for durable logs and close stale
subscriptions. In-memory logs are intended for tests and short-lived
sessions.

## `HEARTBEAT_LOST`

The runtime did not observe expected job heartbeats. Check the tool
handler's loop and cancellation handling.

## `DUPLICATE_KEY`

Logical idempotency keys are scoped by principal. Reuse only when a retry
is semantically the same operation.

## `AGENT_VERSION_NOT_AVAILABLE`

The runtime has the requested agent name, but not the pinned version.
Use `registerToolVersion()` and `setDefaultToolVersion()` on the
runtime, or submit the bare agent name.

## Events arrive but the client call never resolves

Only terminal correlated envelopes resolve `PendingRegistry` waiters.
For streaming updates, subscribe or watch the client's result chunk
assembler.

## Lint / typecheck errors after upgrade

Run:

```sh
composer format
composer gates
```

Most failures are strict array shapes in samples or missing typed
exceptions after adding a new wire code.

## Still stuck?

Capture the envelope JSON and open an issue with the failing command or
test.
