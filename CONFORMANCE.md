# Conformance Matrix — ARCP PHP SDK v0.1

This file tracks the implementation status of every RFC-mandated feature in
[RFC-0001-v2.md](RFC-0001-v2.md). It is updated at the close of every phase.

| Status | Meaning |
| --- | --- |
| ✅ done | Implemented and tested. |
| 🚧 wip | Phase target; partial implementation in tree. |
| ⏭️ deferred | Out of scope for v0.1; throws `UnimplementedException` at boundary. |
| ❌ missing | Required by RFC but not yet implemented (should be empty at v0.1.0 tag). |

## §6 Core protocol

| Feature | Status |
| --- | --- |
| Envelope (§6.1) | ✅ done |
| Idempotency: `id` (transport) (§6.4) | ✅ done |
| Idempotency: `idempotency_key` (logical) (§6.4) | ✅ done |
| Priority + scheduling (§6.5) | 🚧 wip — Phase 3 |
| Critical priority bypass (§6.5) | 🚧 wip — Phase 3 |

## §7 Capability negotiation

| Feature | Status |
| --- | --- |
| Negotiation during handshake | 🚧 wip — Phase 2 |
| Required-but-unsupported → `UNIMPLEMENTED` | 🚧 wip — Phase 2 |

## §8 Authentication & identity

| Feature | Status |
| --- | --- |
| `bearer` | 🚧 wip — Phase 2 |
| `signed_jwt` | 🚧 wip — Phase 2 |
| `none` (with anonymous capability) | 🚧 wip — Phase 2 |
| `mtls` | ⏭️ deferred to v0.2 |
| `oauth2` | ⏭️ deferred to v0.2 |
| `session.refresh` re-auth | 🚧 wip — Phase 2 |
| `session.evicted` | 🚧 wip — Phase 2 |

## §9 Sessions

| Feature | Status |
| --- | --- |
| Stateless | 🚧 wip — Phase 2 |
| Stateful | 🚧 wip — Phase 2 |
| Durable across reconnects | ⏭️ deferred to v0.2 |
| `session.close` graceful shutdown | 🚧 wip — Phase 2 |

## §10 Jobs

| Feature | Status |
| --- | --- |
| State machine (§10.2) | 🚧 wip — Phase 3 |
| Heartbeats (§10.3) | 🚧 wip — Phase 3 |
| Cancellation (§10.4) | 🚧 wip — Phase 3 |
| Interrupts (§10.5) | 🚧 wip — Phase 3 |
| Scheduled jobs (§10.6) | ⏭️ deferred to v0.2 |

## §11 Streaming

| Feature | Status |
| --- | --- |
| `text` stream | 🚧 wip — Phase 3 |
| `binary` stream (base64) | 🚧 wip — Phase 3 |
| `binary` stream (sidecar frames) | ⏭️ deferred to v0.2 |
| `event` stream | 🚧 wip — Phase 3 |
| `log` stream | 🚧 wip — Phase 3 |
| `metric` stream-kind | ⏭️ deferred (use `metric` event) |
| `thought` (reasoning) stream | 🚧 wip — Phase 3 |
| Backpressure (§11.2) | 🚧 wip — Phase 3 |

## §12 Human-in-the-loop

| Feature | Status |
| --- | --- |
| `human.input.request/response` | 🚧 wip — Phase 4 |
| `human.choice.request/response` | 🚧 wip — Phase 4 |
| `human.input.cancelled` | 🚧 wip — Phase 4 |
| Default fallback on expiry | 🚧 wip — Phase 4 |
| Multi-channel first-response wins | 🚧 wip — Phase 4 |
| Quorum response policy | ⏭️ deferred to v0.2 |

## §13 Subscriptions

| Feature | Status |
| --- | --- |
| Subscribe / unsubscribe | 🚧 wip — Phase 5 |
| Filter dimensions | 🚧 wip — Phase 5 |
| Backfill (§13.3) | 🚧 wip — Phase 5 |
| `subscribe.closed` (server-side termination) | 🚧 wip — Phase 5 |

## §14 Multi-agent coordination

| Feature | Status |
| --- | --- |
| `agent.delegate` | ⏭️ deferred to v0.2 |
| `agent.handoff` | ⏭️ deferred to v0.2 |

## §15 Permissions & leases

| Feature | Status |
| --- | --- |
| Permission challenge flow (§15.4) | 🚧 wip — Phase 4 |
| Lease lifecycle (§15.5) | 🚧 wip — Phase 4 |
| Trust elevation (§15.6) | ⏭️ deferred to v0.2 |

## §16 Artifacts

| Feature | Status |
| --- | --- |
| `artifact.put/fetch/ref/release` | 🚧 wip — Phase 5 |
| Inline base64 | 🚧 wip — Phase 5 |
| Sidecar binary frames | ⏭️ deferred to v0.2 |
| Retention sweep | 🚧 wip — Phase 5 |

## §17 Observability

| Feature | Status |
| --- | --- |
| `log` envelope | 🚧 wip — Phase 1 |
| `metric` envelope (standard names) | 🚧 wip — Phase 1 |
| `trace.span` envelope | 🚧 wip — Phase 1 |
| Trace context propagation | 🚧 wip — Phase 1 |

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
| `resume.after_message_id` | 🚧 wip — Phase 5 |
| `resume.checkpoint_id` | ⏭️ deferred to v0.2 |
| `DATA_LOSS` on retention loss | 🚧 wip — Phase 5 |

## §21 Extensions

| Feature | Status |
| --- | --- |
| Naming rules (§21.1) | ✅ done |
| Extension capability negotiation (§21.2) | 🚧 wip — Phase 2 |
| Unknown message handling (§21.3) | ✅ done (envelope dispatch); session wiring Phase 2 |

## §22 Transports

| Feature | Status |
| --- | --- |
| WebSocket | 🚧 wip — Phase 6 |
| stdio | 🚧 wip — Phase 6 |
| HTTP/2 | ⏭️ deferred to v0.2 |
| QUIC | ⏭️ deferred to v0.2 |
