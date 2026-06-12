# Upgrade Guide

The ARCP PHP SDK follows Semantic Versioning. Every breaking change
ships on a major bump, with a deprecation cycle of at least one minor
release where feasible.

This document covers upgrades that need user attention. The full release
history lives in `CHANGELOG.md`.

---

## Unreleased — spec conformance (draft-arcp-1.1)

This release aligns the wire format with the authoritative
draft-arcp-1.1 specification (§5 wire format, §6 sessions, §7 jobs,
§8 events/results, §12 error taxonomy). Every change below is breaking
at the wire level; in-process code breaks where noted.

### Wire-type renames (old → new)

| Old type             | New type          | Payload changes |
| -------------------- | ----------------- | --------------- |
| `session.open`       | `session.hello`   | unchanged (class `SessionOpen` → `SessionHello`) |
| `session.accepted`   | `session.welcome` | unchanged in this phase (class `SessionAccepted` → `SessionWelcome`; §6.2 `resume_token` / `resume_window_sec` / `heartbeat_interval_sec` land in the next phase) |
| `ping`               | `session.ping`    | now `{nonce, sent_at}` per §6.4; both fields required (class `Ping` → `SessionPing`) |
| `pong`               | `session.pong`    | now `{ping_nonce, received_at}` per §6.4 (class `Pong` → `SessionPong`) |
| `ack`                | `session.ack`     | now `{last_processed_seq}` per §6.5; the runtime records the per-session watermark (class `Ack` → `SessionAck`) |
| `resume`             | `session.resume`  | payload unchanged in this phase; §6.3 `last_event_seq` + `resume_token` land with resume-token support (class `Resume` → `SessionResume`) |
| `cancel`             | `job.cancel`      | `{job_id, reason?}`; the `target` enum and `deadline_ms` are gone (§7.4). Acknowledged with `job.cancelled`; `cancel.accepted` / `cancel.refused` are removed (class `Cancel` → `JobCancel`) |
| `subscribe`          | `job.subscribe`   | `{job_id, from_event_seq?, history?}` per §7.6 — the generic filter map and `since.after_message_id` are gone (class `Subscribe` → `JobSubscribe`) |
| `subscribe.accepted` | `job.subscribed`  | full §7.6 payload `{job_id, current_status, agent, lease, parent_job_id, trace_id, subscribed_from, replayed}` (class `SubscribeAccepted` → `JobSubscribed`) |
| `unsubscribe`        | `job.unsubscribe` | `{job_id}` per §7.6; no acknowledgement is sent (class `Unsubscribe` → `JobUnsubscribe`) |
| `tool.invoke`        | `job.submit`      | `{agent, input, lease_request?, lease_constraints?, idempotency_key?, max_runtime_sec?}` per §7.1 (class `ToolInvoke` removed; `JobSubmit` added) |
| `tool.result`        | `job.result`      | `{final_status, result?, result_id?, result_size?, summary?}` per §7.3/§8.4 (classes `ToolResult` / `JobCompleted` removed; `JobResult` added) |
| `tool.error`         | `job.error`       | `{final_status, code, message, retryable, details?}` per §12 (classes `ToolError` / `JobFailed` removed; `JobError` added) |
| `job.completed`      | `job.result`      | see above |
| `job.failed`         | `job.error`       | see above |
| `job.started`        | `job.event`       | the runtime now emits a §8.1 `job.event` (`{kind: "status", ts, body: {phase: "running"}}`); `JobStarted` is removed and `JobEvent` added |

`job.accepted` keeps its type name but its payload is now the full §7.1
shape: `{job_id, agent, lease, lease_constraints, budget, credentials,
accepted_at, trace_id}` (previously only `note` / `credentials`).

### Envelope

- New optional `event_seq` field (§5/§8.1): a session-scoped,
  monotonically increasing sequence stamped on sequenced messages (job
  events and results). Round-trips through the serializer; `Envelope`
  exposes `?int $eventSeq`.
- Unknown wire types no longer raise on decode (§5): the serializer
  returns an envelope carrying `Arcp\Envelope\UnknownMessage` (which
  preserves the original type and payload) and both dispatch loops log
  and skip it. Only a *mandatory* unadvertised extension type still
  raises `INVALID_REQUEST`.

### Error taxonomy (§12)

`Arcp\Errors\ErrorCode` is now exactly the §12 set:
`PERMISSION_DENIED`, `LEASE_SUBSET_VIOLATION`, `JOB_NOT_FOUND`,
`DUPLICATE_KEY`, `AGENT_NOT_AVAILABLE`, `AGENT_VERSION_NOT_AVAILABLE`,
`CANCELLED`, `TIMEOUT`, `RESUME_WINDOW_EXPIRED`, `HEARTBEAT_LOST`,
`LEASE_EXPIRED`, `BUDGET_EXHAUSTED`, `INVALID_REQUEST`,
`UNAUTHENTICATED`, `INTERNAL_ERROR`. Retryable-by-default: `TIMEOUT`,
`HEARTBEAT_LOST`, `INTERNAL_ERROR`; `LEASE_EXPIRED` and
`BUDGET_EXHAUSTED` are always `retryable: false`.

Old → new code/exception mapping:

| Old | New |
| --- | --- |
| `INVALID_ARGUMENT` / `InvalidArgumentException` | `INVALID_REQUEST` / `InvalidRequestException` |
| `INTERNAL` / `InternalException` | `INTERNAL_ERROR` / `InternalErrorException` |
| `DEADLINE_EXCEEDED` / `DeadlineExceededException` | `TIMEOUT` / `TimeoutException` |
| `NOT_FOUND` (job lookups) / `NotFoundException` | `JOB_NOT_FOUND` / `JobNotFoundException` |
| `NOT_FOUND` (tool/agent lookups) | `AGENT_NOT_AVAILABLE` / `AgentNotAvailableException` |
| `DATA_LOSS` on resume-buffer miss | `RESUME_WINDOW_EXPIRED` / `ResumeWindowExpiredException` |
| `UNIMPLEMENTED` (capability mismatch, un-negotiated feature) | `INVALID_REQUEST` |
| `UNIMPLEMENTED` (reserved auth scheme) | `UNAUTHENTICATED` |
| `ALREADY_EXISTS` (conflicting idempotency reuse) | `DUPLICATE_KEY` / `DuplicateKeyException` |
| `LEASE_REVOKED` / `LeaseRevokedException` | `PERMISSION_DENIED` (revoked-lease use is a lease-enforcement rejection) |
| `OK`, `UNKNOWN`, `RATE_LIMITED`, `RESOURCE_EXHAUSTED`, `FAILED_PRECONDITION`, `ABORTED`, `OUT_OF_RANGE`, `UNAVAILABLE`, `DATA_LOSS`, `BACKPRESSURE_OVERFLOW` | removed; inbound unknown codes map to `INTERNAL_ERROR` with `details.raw_code` preserved |

`ErrorPayload` keeps the §12 shape (`code` / `message` / `retryable` /
`details`); the `RATE_LIMITED` alias is gone.
`TransportClosedException` now reports `HEARTBEAT_LOST`.

### Job lifecycle (§7.3)

`Arcp\Runtime\JobState` is now `pending`, `running`, plus the
terminals `success`, `error`, `cancelled`, `timed_out`. The
`accepted`, `queued`, `blocked`, `paused`, `cancelling`, `completed`,
and `failed` states are removed. `session.list_jobs` status filters and
entries use the new strings (e.g. filter on `success`, not
`completed`).

### Client API

- `ARCPClient::invokeTool()` returns `JobResult` (read `->result`, not
  `->value`) and accepts `leaseRequest`, `leaseConstraints`, and
  `maxRuntimeSec` options. The wire message is `job.submit`.
- `ARCPClient::subscribe(JobId $jobId, \Closure $onEvent,
  ?int $fromEventSeq = null, bool $history = false)` returns
  `JobSubscribed`; `unsubscribe(JobId $jobId)` replaces the
  subscription-id variant. Subscriptions are job-scoped per §7.6.
- `ARCPClient::cancelJob(JobId $jobId, string $reason)` — the
  `deadlineMs` parameter is gone (§7.4 defines no cancel deadline).
- `ARCPClient::ping()` returns `SessionPong` (`->pingNonce`,
  `->receivedAt`); `ARCPClient::ack(int $lastProcessedSeq)` sends the
  §6.5 advisory watermark.
- `releaseArtifact()` is acknowledged by the new `artifact.released`
  message instead of a generic `ack` (SDK artifact extension surface).

---

## v1.x — internal SDK refactor

The v1.x line introduces no breaking changes for code that uses the
documented public surface (`Arcp\Client\ARCPClient`, `Arcp\Runtime\ARCPRuntime`,
the `Arcp\Messages\...` value objects, the `Arcp\Errors\...` exception
hierarchy, and the `Arcp\Transport\Transport` adapter contract).

What changed:

### Additive — new root exception marker

`Arcp\Errors\ARCPExceptionInterface` is the new root marker for every
exception the SDK can throw. Existing `catch (\Arcp\Errors\ARCPException $e)`
blocks continue to work — the abstract `ARCPException` now implements
the interface but its base class and constructor are unchanged. The
recommended pattern going forward:

```php
try {
    $client->invokeTool('search', ['q' => $query]);
} catch (\Arcp\Errors\ARCPExceptionInterface $e) {
    $logger->warning('arcp call failed', [
        'code' => $e->code()->value,
        'retryable' => $e->isRetryable(),
    ]);
}
```

### Additive — `TransportClosedException`

Three transports (`StdioTransport`, `MemoryTransport`, `WebSocketTransport`)
previously threw bare `\RuntimeException('... closed')` when called after
close. They now throw `Arcp\Errors\TransportClosedException`, which
descends from `Arcp\Errors\ARCPException` (which extends
`\RuntimeException`). Existing `catch (\RuntimeException $e)` blocks
keep working; new code can catch the typed exception or
`ARCPExceptionInterface`.

### Internal — `Arcp\Internal\...` namespace

Runtime and client internals (`HandshakeNegotiator`,
`ToolInvocationHandler`, `SubscriptionRouter`, `LifecycleHandler`,
`ArtifactDispatcher`, `ResponseRouter`, etc.) live under
`Arcp\Internal\...`. They are marked `@internal` and are NOT part of the
backward-compatibility promise. Application code should depend only on
`Arcp\Client\ARCPClient` and `Arcp\Runtime\ARCPRuntime`.

### Internal — validation exceptions

A handful of internal validation paths in `Envelope`, `Id`, and
`ErrorPayload` previously threw the SPL `\InvalidArgumentException`.
They now throw `Arcp\Errors\InvalidArgumentException` (which extends
SPL's `\RuntimeException` via `ARCPException`).

If you have tests that explicitly assert the SPL type, update them to
the local type:

```diff
- $this->expectException(\InvalidArgumentException::class);
+ $this->expectException(\Arcp\Errors\InvalidArgumentException::class);
```

If you catch by `\Throwable` or by the local
`Arcp\Errors\InvalidArgumentException` already, no action needed.

### Tooling — Rector, Infection, size-check

New dev dependencies (`rector/rector`, `infection/infection`) and a
new `tools/size-check.php` gate enforce PHP_SDK_GUIDE §14 limits.
`composer all` runs every check; CI configurations should adopt it.
