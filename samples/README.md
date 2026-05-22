# ARCP PHP samples

Nineteen single-purpose sample programs, each named for the protocol
primitive it demonstrates. Mirrors the Python tree at
`python-sdk/examples/` and the cross-language brief in
`python-sdk/examples/SUBWORKER_BRIEF.md`.

> **Illustrative, not runnable.** Each sample imports from the in-repo
> `Arcp\` namespace as if it were a published `arcp/arcp ^1.0` package.
> Setup boilerplate (transport URL, identity, auth) is elided behind an
> `elided()` helper that throws. LLM and framework calls live in tiny
> stub modules (`agents.php`, `steps.php`, `cheap.php`, …) so the
> protocol code in `main.php` is what you read.

## The nineteen

| Directory | Demonstrates | Spec |
|---|---|---|
| [`subscriptions/`](./subscriptions) | Three Observer clients on one session, three filters, three sinks. | §5, §13 |
| [`leases/`](./leases) | Lease-gated shell agent. Read leases coarse, write leases scoped. | §15.4–§15.5 |
| [`lease_revocation/`](./lease_revocation) | Per-table leases with `lease.revoked` / `lease.extended` mid-flight. | §15.5 |
| [`permission_challenge/`](./permission_challenge) | Two-party permission challenge — generator asks, reviewer holds veto. | §15.4, §6.4 |
| [`delegation/`](./delegation) | `agent.delegate` fan-out + `JobMux` to demux events by `job_id`. | §14, §6.4 |
| [`handoff/`](./handoff) | `agent.handoff` with transcript packed as an artifact, runtime fingerprint pinned. | §14, §16, §8.3 |
| [`heartbeats/`](./heartbeats) | Worker federation; heartbeat-loss reroute via `idempotency_key`. | §10.3, §6.4 |
| [`capability_negotiation/`](./capability_negotiation) | Capability-driven peer routing; standard `cost.usd` rollups. | §7, §17.3.1, §18.3 |
| [`resumability/`](./resumability) | **Actually crash and resume.** `exit(137)` mid-flight; second invocation picks up at the next step. | §10, §19, §6.4 |
| [`reasoning_streams/`](./reasoning_streams) | `kind: thought` stream + a peer runtime that subscribes and delegates critiques back. | §11.4, §13, §14 |
| [`extensions/`](./extensions) | Custom `arcpx.sdr.*.v1` extension namespace with correct unknown-message handling. | §21 |
| [`human_input/`](./human_input) | `human.input.request` fanned across phone/email/Slack; first-wins resolution. | §12 |
| [`cancellation/`](./cancellation) | Cooperative `cancel` (terminate) vs `interrupt` (pause and ask). | §10.4–§10.5 |
| [`mcp/`](./mcp) | ARCP runtime fronting an MCP server: `tool.invoke` → MCP `call_tool`. | §20 |
| [`list_jobs/`](./list_jobs) | `session.list_jobs` read-only inventory with status/agent filtering. | §6.6 |
| [`agent_versions/`](./agent_versions) | `name@version` resolution and missing-version errors. | §7.5, §12 |
| [`result_chunk/`](./result_chunk) | Chunked terminal results assembled by the client. | §8.4 |
| [`cost_budget/`](./cost_budget) | `cost.budget` counters decremented by `cost.*` metrics. | §9.6, §12 |
| [`provisioned_credentials/`](./provisioned_credentials) | Lease-bound upstream credentials for model and budget enforcement. | §9.7–§9.8 |

## Conventions

- PHP 8.4+, `declare(strict_types=1)` everywhere.
- PSR-4 namespaces under `Arcp\Samples\<Name>\` for stub modules.
- One `main.php` per directory carrying the protocol code; 0–2 stub
  modules named for what they elide (`agents.php`, `steps.php`,
  `cheap.php`, `synth.php`, `work.php`, `channels.php`, `sql.php`,
  `upstream.php`, `sinks/*.php`).
- `elided()` literally — transport, identity, and auth blocks are
  setup noise, not the point. The function throws, so samples are
  read-only blueprints.
- Envelopes match RFC-0001 v2 exactly. Custom message types follow
  §21.1 `arcpx.<domain>.<name>.v<n>` naming.

## What's where in the SDK

- `Arcp\Client\ARCPClient` — handshake driver; `invokeTool`,
  `subscribe`, `cancelJob`, `putArtifact`.
- `Arcp\Envelope\Envelope`, `Arcp\Errors\ErrorCode`,
  `Arcp\Errors\ARCPException` — wire primitives.
- `Arcp\Ids\MessageId::random()` — for runtime-side code that builds
  envelopes outside an `ARCPClient`.
- `Arcp\Transport\WebsocketTransport` — most common transport.
- `Arcp\Store\` — eventlog schema reused by `subscriptions`.

## Reading order

For a brisk tour: `subscriptions`, `leases`, `delegation`,
`resumability` (this one actually crashes and recovers),
`cancellation`, `extensions`, `mcp`. These seven exercise the bulk
of the protocol.
