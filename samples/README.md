# ARCP PHP samples

Single-purpose PHP sample blueprints named after the canonical
cross-SDK examples. Most samples elide transport/auth setup behind an
`elided()` helper so the protocol flow stays visible.

## Canonical examples

| Directory | Demonstrates | Spec |
| --- | --- | --- |
| [`ack-backpressure/`](./ack-backpressure) | Ack-driven flow control and back-pressure status events. | §6.5, §8.2 |
| [`agent-versions/`](./agent-versions) | `name@version` resolution and missing-version errors. | §7.5, §12 |
| [`cancel/`](./cancel) | Cooperative `cancel` and blocked-job interrupt handling. | §10.4-§10.5 |
| [`cost-budget/`](./cost-budget) | `cost.budget` counters decremented by `cost.*` metrics. | §9.6, §12 |
| [`custom-auth/`](./custom-auth) | Custom bearer verifier shape for handshake auth. | §6.1 |
| [`delegate/`](./delegate) | `agent.delegate` fan-out and event demux by `job_id`. | §14, §6.4 |
| [`heartbeat/`](./heartbeat) | Worker federation and heartbeat-loss reroute. | §10.3, §6.4 |
| [`idempotent-retry/`](./idempotent-retry) | Stable `(principal, idempotency_key)` job submission semantics. | §6.4, §7.2 |
| [`lease-expires-at/`](./lease-expires-at) | Lease expiry deadlines enforced before side effects. | §9.5, §12 |
| [`lease-violation/`](./lease-violation) | Out-of-lease operation denied without failing the whole job. | §9.3, §12 |
| [`list-jobs/`](./list-jobs) | `session.list_jobs` inventory with status/agent filtering. | §6.6 |
| [`progress/`](./progress) | Progress events suitable for UI progress bars. | §8.2.1 |
| [`result-chunk/`](./result-chunk) | Chunked terminal results assembled by the client. | §8.4 |
| [`resume/`](./resume) | Crash and resume via event replay and checkpoints. | §10, §19, §6.4 |
| [`stdio/`](./stdio) | Parent/child ARCP over stdin/stdout. | §4.2, §22 |
| [`submit-and-stream/`](./submit-and-stream) | One-shot job with status, log, thought, metric, and artifact events. | §13.1, §7.1, §8.2 |
| [`subscribe/`](./subscribe) | Observer subscriptions with filters and backfill. | §5, §13 |
| [`tracing/`](./tracing) | W3C trace context carried through envelope extensions. | §11 |
| [`vendor-extensions/`](./vendor-extensions) | Custom `arcpx.sdr.*.v1` extension namespace handling. | §21 |

## Additional blueprints

| Directory | Demonstrates | Spec |
| --- | --- | --- |
| [`capability-negotiation/`](./capability-negotiation) | Capability-driven peer routing and retry classification. | §7, §17.3.1, §18.3 |
| [`handoff/`](./handoff) | `agent.handoff` with transcript artifacts and runtime pinning. | §14, §16, §8.3 |
| [`mcp/`](./mcp) | ARCP runtime fronting an MCP server. | §20 |
| [`provisioned-credentials/`](./provisioned-credentials) | Lease-bound upstream credentials for model and budget enforcement. | §9.7-§9.8 |

## Conventions

- PHP 8.4+, `declare(strict_types=1)` everywhere.
- One `main.php` per runnable directory; small helper files only where a sample needs a stubbed agent, sink, or upstream.
- `elided()` means transport, identity, auth, or provider setup that would hide the protocol behavior.
- Vendor message types use §21.1 `arcpx.<domain>.<name>.v<n>` naming.

## Reading order

Start with `submit-and-stream`, `progress`, `subscribe`, `lease-expires-at`,
`delegate`, and `resume`. Those cover the core lifecycle before moving
to auth, tracing, vendor extensions, and MCP.
