# Conformance Matrix — ARCP PHP SDK v0.1

This file tracks the implementation status of every RFC-mandated feature in
[RFC-0001-v2.md](RFC-0001-v2.md). Updated through Phase 7 / v0.1.0 tag.

| Status | Meaning |
| --- | --- |
| ✅ done | Implemented and tested. |
| ⏭️ deferred | Out of scope for v0.1; throws `UnimplementedException` at boundary. |
| ❌ missing | Required by RFC but not yet implemented (must be empty at v0.1.0 tag). |

## §6 Core protocol

| Feature | Status |
| --- | --- |
| Envelope (§6.1) | ✅ done |
| Idempotency: `id` (transport) (§6.4) | ✅ done |
| Idempotency: `idempotency_key` (logical) (§6.4) | ✅ done |
| Priority + scheduling (§6.5) | ✅ done (weighted) |
| Critical priority bypass (§6.5) | ✅ done |

## §7 Capability negotiation

| Feature | Status |
| --- | --- |
| Negotiation during handshake | ✅ done |
| Required-but-unsupported → `UNIMPLEMENTED` | ✅ done |

## §8 Authentication & identity

| Feature | Status |
| --- | --- |
| `bearer` | ✅ done |
| `signed_jwt` | ✅ done |
| `none` (with anonymous capability) | ✅ done |
| `mtls` | ⏭️ deferred to v0.2 |
| `oauth2` | ⏭️ deferred to v0.2 |
| `session.refresh` re-auth | ✅ done (envelope shape; round-trip test deferred) |
| `session.evicted` | ✅ done |

## §9 Sessions

| Feature | Status |
| --- | --- |
| Stateless | ✅ done |
| Stateful | ✅ done |
| Durable across reconnects | ⏭️ deferred to v0.2 |
| `session.close` graceful shutdown | ✅ done |

## §10 Jobs

| Feature | Status |
| --- | --- |
| State machine (§10.2) | ✅ done |
| Heartbeats (§10.3) | ✅ done (manual via `JobContext::heartbeat()`) |
| Cancellation (§10.4) | ✅ done |
| Interrupts (§10.5) | ✅ done (synthesizes `human.input.request`) |
| Scheduled jobs (§10.6) | ⏭️ deferred to v0.2 |

## §11 Streaming

| Feature | Status |
| --- | --- |
| `text` stream | ✅ done |
| `binary` stream (base64) | ✅ done |
| `binary` stream (sidecar frames) | ⏭️ deferred to v0.2 |
| `event` stream | ✅ done |
| `log` stream | ✅ done |
| `metric` stream-kind | ⏭️ deferred (use top-level `metric` event) |
| `thought` (reasoning) stream | ✅ done |
| Backpressure (§11.2) | ✅ done (envelope shape; pipeline gating Phase-3 bounded queue) |

## §12 Human-in-the-loop

| Feature | Status |
| --- | --- |
| `human.input.request/response` | ✅ done |
| `human.choice.request/response` | ✅ done |
| `human.input.cancelled` | ✅ done |
| Default fallback on expiry | ✅ done |
| Multi-channel first-response wins | ✅ done |
| Quorum response policy | ⏭️ deferred to v0.2 |

## §13 Subscriptions

| Feature | Status |
| --- | --- |
| Subscribe / unsubscribe | ✅ done |
| Filter dimensions | ✅ done |
| Backfill (§13.3) | ✅ done |
| `subscribe.closed` (server-side termination) | ✅ done |

## §14 Multi-agent coordination

| Feature | Status |
| --- | --- |
| `agent.delegate` | ⏭️ deferred to v0.2 |
| `agent.handoff` | ⏭️ deferred to v0.2 |

## §15 Permissions & leases

| Feature | Status |
| --- | --- |
| Permission challenge flow (§15.4) | ✅ done |
| Lease lifecycle (§15.5) | ✅ done |
| Trust elevation (§15.6) | ⏭️ deferred to v0.2 |

## §16 Artifacts

| Feature | Status |
| --- | --- |
| `artifact.put/fetch/ref/release` | ✅ done |
| Inline base64 | ✅ done |
| Sidecar binary frames | ⏭️ deferred to v0.2 |
| Retention sweep | ✅ done |

## §17 Observability

| Feature | Status |
| --- | --- |
| `log` envelope | ✅ done |
| `metric` envelope (standard names) | ✅ done |
| `trace.span` envelope | ✅ done |
| Trace context propagation | ✅ done (per-envelope `trace_id`/`span_id`) |

## §18 Errors

| Feature | Status |
| --- | --- |
| Canonical error code taxonomy | ✅ done |
| Typed exception hierarchy | ✅ done |
| `retryable` flag | ✅ done |
| `cause` chaining | ✅ done |

## §19 Resume

| Feature | Status |
| --- | --- |
| `resume.after_message_id` | ✅ done |
| `resume.checkpoint_id` | ⏭️ deferred to v0.2 |
| `DATA_LOSS` on retention loss | ✅ done |

## §21 Extensions

| Feature | Status |
| --- | --- |
| Naming rules (§21.1) | ✅ done |
| Extension capability negotiation (§21.2) | ✅ done |
| Unknown message handling (§21.3) | ✅ done |

## §22 Transports

| Feature | Status |
| --- | --- |
| WebSocket | ✅ done |
| stdio | ✅ done |
| HTTP/2 | ⏭️ deferred to v0.2 |
| QUIC | ⏭️ deferred to v0.2 |
