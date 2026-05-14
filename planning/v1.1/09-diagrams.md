# 09 — Diagrams

Graphviz sources land under `docs/diagrams/*.dot`; rendered SVGs sit
beside them. The PHP SDK ships diagrams in source and rendered form
because the shared docs site at `arcp.dev` ingests Markdown that
references SVGs via relative paths — a consumer cloning `arcp/arcp`
to read offline must not need `brew install graphviz`. This mirrors
the layout under `../typescript-sdk/diagrams/` (per `ls`:
`architecture-{light,dark}.{dot,svg}`, `job-lifecycle-{light,dark}`,
`session-handshake-{light,dark}`, plus `diagram-template-{light,dark}.dot`).
The PHP set is single-variant (no light/dark split) until the docs
site signals it needs the `<picture>` swap; if that happens we
duplicate template once and the existing diagrams compile against
both.

The set below covers every v1.1 surface the spec adds. The job-FSM
and session-FSM split avoids the worked-example mistake of cramming
"connection state" and "work state" into one graph — the spec keeps
them apart (§6 vs §7–§9) and the diagrams follow.

## 0. Shared style

One header block; every `.dot` repeats it verbatim. Naming the rules
once here, not eight times below.

- `bgcolor=white` on `digraph` / `graph`.
- Graph: `fontname="Helvetica"`, `fontsize=10`.
- Default node: `shape=box, style="rounded,filled",
  fillcolor="#f8f9fa", fontname="Helvetica", fontsize=10,
  color="#cbd5e1"`.
- Default edge: `fontname="Helvetica", fontsize=9,
  color="#475569"`.
- FSM terminal states override to `shape=doublecircle, fillcolor="#fee2e2"`
  (red-50) for error states, `fillcolor="#dcfce7"` (green-50) for
  success terminals.
- Sequence-like diagrams set `rankdir=TB` and use one subgraph
  cluster per participant (`cluster_client`, `cluster_runtime`,
  `cluster_agent`) drawn as boxes so the lane is visible without
  needing `mscgen`. Edges between lanes carry the wire envelope
  `type` as the edge label.
- Captions go in a `label="..."` on the graph itself, suffixed with
  the spec § the diagram cites (Graphviz renders this at the bottom).
- No colors beyond the slate/red/green/amber palette above. The
  TS diagrams' `prefers-color-scheme` blue/amber palette is reserved
  for a later light/dark split.

Phase 03 has not picked a dedicated sequence-diagram tool. `dot` with
ranked subgraphs is enough for §6.4 / §6.5 / §8.4; if Phase 10
chooses `mscgen` or PlantUML the file extension and render command
change, the diagram inventory does not.

## 1. Diagram inventory

### (a) `namespace-deps.dot` — package and namespace dependency graph

- **Purpose:** Visualize the directed edges between every core
  namespace under `Arcp\\` and the six side packages, so a reviewer
  can confirm `Arcp\Envelope` depends on nothing in `Arcp\Client`
  (the inversion that Phase 04 §1.1 names).
- **Render:** `dot -Tsvg docs/diagrams/namespace-deps.dot -o docs/diagrams/namespace-deps.svg`.
- **Nodes:** one box per namespace listed in Phase 04 §1.1 —
  `Arcp\Envelope`, `Arcp\Session`, `Arcp\Job`, `Arcp\Lease`,
  `Arcp\Errors`, `Arcp\Transport`, `Arcp\Auth`, `Arcp\Trace`,
  `Arcp\Ids`, `Arcp\Clock`, `Arcp\Client`, `Arcp\Runtime`; plus
  side-package nodes from Phase 04 §1 (`arcp/cli`, `arcp/auth-jwt`)
  and from Phase 05 (`arcp/otel`, `arcp/psr15`, `arcp/amphp-server`,
  `arcp/laravel`, `arcp/symfony-bundle`). External-dep nodes
  (`amphp/amp`, `amphp/pipeline`, `amphp/websocket-client`, `psr/log`,
  `symfony/uid`, `firebase/php-jwt`, `symfony/console`) cluster in a
  `cluster_vendor` subgraph at the bottom so the in-package edges
  dominate visually.
- **Edges:** `A -> B` means "A uses B at the type level." Bottom-up
  reads: `Envelope` and `Ids` are sinks; `Session`, `Job`, `Lease`,
  `Errors`, `Transport`, `Auth`, `Trace` depend on `Envelope`;
  `Client` and `Runtime` depend on everything above plus
  `amphp/amp`, `amphp/pipeline`, `psr/log`. Side packages each
  carry one edge into `arcp/arcp` and one into their justifying
  vendor (`arcp/cli` → `symfony/console`; `arcp/auth-jwt` →
  `firebase/php-jwt`; `arcp/otel` → `open-telemetry/api`;
  `arcp/laravel` → `illuminate/contracts`).
- **Enforcement note:** the caption reads "Enforced by phpat or
  deptrac (Phase 03 decides; not chosen at v1.1 plan time)." If
  Phase 03 lands on `qossmic/deptrac`, the `deptrac.yaml` ruleset is
  this diagram in YAML. If by-hand stays the call (smallest cost),
  the diagram is the source of truth and `composer test:arch`
  doesn't exist — the README points reviewers at this SVG.
- **Caption §:** Phase 04 §1.1 (namespace map), Phase 03 (tool
  pick).

### (b) `session-fsm.dot` — session finite-state machine

- **Purpose:** Show the lifecycle of a `Arcp\Session\Session` from
  open to close, including the v1.1 heartbeat-loss path that Phase
  01 §1 row §6.4 adds. The job FSM (c) renders separately because
  the session FSM is about connection control (§6) and the job FSM
  is about work (§7–§9).
- **Render:** `dot -Tsvg docs/diagrams/session-fsm.dot -o docs/diagrams/session-fsm.svg`.
- **Nodes:** `init` (entry), `hello_sent`, `welcome_received`,
  `error` (terminal, error fill), `live`, `pinging`,
  `closed` (terminal, success fill). `pinging` is a sub-state of
  `live` — represent as a small cluster around `live` and `pinging`
  labeled "heartbeat (§6.4)" so the reader sees pinging does not
  leave `live` from the application's perspective; it's an internal
  timer state of the same long-lived `Session`.
- **Edges:** `init -> hello_sent` labeled `send session.hello`;
  `hello_sent -> welcome_received` on `recv session.welcome`;
  `hello_sent -> error` on `decode failure` or
  `unsupported version`; `welcome_received -> live` on
  `intersect capabilities`; `live -> pinging` on
  `idle ≥ heartbeat_interval`; `pinging -> live` on `recv pong`;
  `pinging -> closed` on `2× silence ⇒ HEARTBEAT_LOST` (red edge,
  cites §6.4); `live -> closed` on `recv session.close` or
  `send session.close`; `error -> closed` on `transport.close()`.
- **PHP idiom note in caption:** "Transitions wired via
  `match (MessageType::from($wire['type']))` in
  `Arcp\Session\SessionMachine` (Phase 04 §2). No global state; the
  machine instance is owned by the `ArcpClient` or `Server`."
- **Caption §:** §6.1, §6.4.

### (c) `job-fsm.dot` — job finite-state machine

- **Purpose:** Express the v1.1 job lifecycle including the two new
  terminal error reasons (`LEASE_EXPIRED` §9.5, `BUDGET_EXHAUSTED`
  §9.6) and the §7.6 subscribe-as-passive-listener distinction
  (subscribing does not advance the FSM, it adds an observer).
- **Render:** `dot -Tsvg docs/diagrams/job-fsm.dot -o docs/diagrams/job-fsm.svg`.
- **Nodes:** `submit_sent`, `accepted`, `running`, `success`
  (terminal, success fill), and one terminal `error` cluster
  containing four labeled error states using
  `final_status="error"` with distinct `error.code` values —
  `cancelled` (§7.4), `timed_out` (§7.3 timeout), `lease_expired`
  (§9.5), `budget_exhausted` (§9.6); each as a `doublecircle` with
  red fill. A separate `subscriber_attached` node sits to the side
  of `running` with a dashed edge in and dashed edge out (no state
  change for the FSM owner) carrying the label `job.subscribed`
  (§7.6).
- **Edges:** `submit_sent -> accepted` on `recv job.accepted`;
  `submit_sent -> error{cancelled}` if the submitter cancels before
  accept; `accepted -> running` on first event; `running -> success`
  on `recv job.result { final_status: success }`; `running ->
  error{cancelled}` on `recv job.error { code: CANCELLED }`;
  `running -> error{timed_out}` on
  `recv job.error { code: TIMEOUT }`; `running -> error{lease_expired}`
  on `recv job.error { code: LEASE_EXPIRED }` (§9.5 — runtime emits
  on authority op at-or-after `expires_at`, or proactively); `running
  -> error{budget_exhausted}` on
  `recv job.error { code: BUDGET_EXHAUSTED }` (§9.6 — when runtime
  treats counter-zero as fatal rather than surfacing through
  `tool_result`). The dashed `running ↔ subscriber_attached` pair
  marks that `job.subscribe` / `job.unsubscribe` do not advance the
  FSM for the submitter — they install or remove an observer in the
  runtime's event-fan-out path.
- **PHP idiom note in caption:** "Each terminal corresponds to one
  `final class …Exception extends ArcpException` in `Arcp\Errors`
  (Phase 04 §4.1). Adding a v1.2 terminal forces a new `match` arm
  in `Arcp\Client\JobMachine::onError()` — PHPStan max flags the
  missing arm."
- **Caption §:** §7.1–§7.4, §7.6, §9.5, §9.6.

### (d) `capability-negotiation.dot` — `session.hello` ↔ `session.welcome`

- **Purpose:** Show the §6.2 intersection rule that Phase 01 §3.4
  encodes in `CapabilitySet::intersect()`. The diagram makes
  visible that the runtime computes the intersection and echoes it,
  and that both sides store the same effective set — the place
  every feature-gated send-site reads from.
- **Render:** `dot -Tsvg docs/diagrams/capability-negotiation.dot -o docs/diagrams/capability-negotiation.svg`.
- **Nodes:** two lane clusters (`cluster_client`, `cluster_runtime`).
  Inside `cluster_client`: `client.hello_built`,
  `client.welcome_received`, `client.effective_stored`. Inside
  `cluster_runtime`: `runtime.hello_received`,
  `runtime.intersection_computed`,
  `runtime.welcome_sent`, `runtime.effective_stored`. Use
  `rankdir=TB` so each lane reads top-to-bottom with time.
- **Edges:** `client.hello_built -> runtime.hello_received` labeled
  `session.hello { features: [heartbeat, ack, list_jobs, subscribe,
  lease_expires_at, cost.budget, progress, result_chunk,
  agent_versions] }` (the full `Feature::cases()` set from Phase 01
  §3.1); `runtime.hello_received -> runtime.intersection_computed`;
  `runtime.intersection_computed -> runtime.welcome_sent`;
  `runtime.welcome_sent -> client.welcome_received` labeled
  `session.welcome { features: intersection, agents:
  AgentInventory }`; vertical edges inside each lane lead to the
  matching `effective_stored` node.
- **PHP idiom note in caption:** "Each peer stores the effective set
  on the immutable `readonly` `Arcp\Session\Session` value object
  (Phase 04 §5). Feature-gated send-sites read
  `$session->effective->supports(Feature::Subscribe)` before
  emitting; an unguarded send is a bug, not a runtime error
  (Phase 01 §3.4)."
- **Caption §:** §6.2, §7.5.

### (e) `heartbeat-flow.dot` — `session.ping` / `session.pong` over two intervals

- **Purpose:** Show the §6.4 mechanics: an idle peer pings, the
  counterpart pongs, and two consecutive silent intervals trip the
  `HEARTBEAT_LOST` close. Phase 04 §3.2 names the timers as
  `Revolt\EventLoop::repeat()` plus a `delay()` deadline; this
  diagram is the wire view, not the timer view.
- **Render:** `dot -Tsvg docs/diagrams/heartbeat-flow.dot -o docs/diagrams/heartbeat-flow.svg`.
- **Nodes:** lanes `cluster_a` (peer A — say, the runtime) and
  `cluster_b` (peer B — client). Per-lane nodes named
  `a.t0_idle`, `a.t1_ping_sent`, `a.t2_pong_received`,
  `a.t3_idle_again`, `a.t4_ping_sent`, `a.t5_no_pong_deadline`,
  `a.t6_close_heartbeat_lost`, with the matching `b.tN` on B's
  side. `rankdir=TB`, ranks align so the reader sees the time axis
  vertically.
- **Edges:** `a.t1_ping_sent -> b.t1_pong_replied` labeled
  `session.ping { nonce, sent_at }` (§6.4);
  `b.t1_pong_replied -> a.t2_pong_received` labeled
  `session.pong { ping_nonce, received_at }`. Second interval:
  `a.t4_ping_sent -> b.t4_silent` labeled `session.ping`; no reply
  edge. Red edge `a.t5_no_pong_deadline -> a.t6_close_heartbeat_lost`
  labeled `2 × heartbeat_interval_sec elapsed ⇒ HEARTBEAT_LOST` and a
  dashed cross-lane edge `a.t6_close_heartbeat_lost -> b.t6_transport_closed`
  representing the transport-level close.
- **PHP idiom note in caption:** "Idle timer and silence deadline
  are `EventLoop::repeat()` / `EventLoop::delay()` IDs stored on
  `Arcp\Session\Session`; `close()` cancels both via
  `EventLoop::cancel($id)` (Phase 04 §3.2). `HEARTBEAT_LOST` raises
  `Arcp\Errors\HeartbeatLostException` (Phase 04 §4.1)."
- **Caption §:** §6.4.

### (f) `ack-flow.dot` — event acknowledgment and early buffer eviction

- **Purpose:** Show §6.5: runtime emits a stream of events, client
  periodically acks `last_processed_seq`, runtime MAY evict events
  with `seq ≤ ack` from the resume buffer early. The diagram makes
  the buffer-side effect visible — the spec sentence is short, the
  consequence for the runtime's memory profile is what readers
  actually want to see.
- **Render:** `dot -Tsvg docs/diagrams/ack-flow.dot -o docs/diagrams/ack-flow.svg`.
- **Nodes:** lanes `cluster_runtime` and `cluster_client`. Runtime
  side: `runtime.emit_e1` through `runtime.emit_e5`; a separate
  `runtime.buffer` node (`shape=cylinder` per the worked example's
  data-store convention) showing the buffered set after each
  emission. Client side: `client.recv_e1` through
  `client.recv_e5`, `client.ack_seq3`.
- **Edges:** five `runtime.emit_eN -> client.recv_eN` edges labeled
  `job.event { event_seq: N }`; an upward edge
  `client.ack_seq3 -> runtime.recv_ack` labeled
  `session.ack { last_processed_seq: 3 }`; a dashed self-edge on
  `runtime.buffer` labeled `evict seq ≤ 3` showing the buffer
  shrinks. After the eviction, the buffer node's label reads
  `{ 4, 5 }`; before, `{ 1, 2, 3, 4, 5 }`.
- **PHP idiom note in caption:** "Client send-cadence: `Amp\delay()`
  in a fiber loop scheduling one `session.ack` per processed event
  OR per ~250 ms, whichever is less frequent (§6.5 SHOULD).
  `session.ack` does not occupy `event_seq` space and is therefore
  not gated by `Pipeline` back-pressure (Phase 04 §3)."
- **Caption §:** §6.5.

### (g) `result-chunk-sequence.dot` — streamed result assembly

- **Purpose:** Show §8.4 end-to-end: agent decides to stream, runtime
  allocates `result_id`, chunks `0..N-1` flow with `more: true`, the
  final chunk carries `more: false`, and `job.result` with
  `result_id` and `result_size` terminates. Make explicit that
  inline result is mutually exclusive (the spec's MUST NOT mix).
- **Render:** `dot -Tsvg docs/diagrams/result-chunk-sequence.dot -o docs/diagrams/result-chunk-sequence.svg`.
- **Nodes:** lanes `cluster_agent`, `cluster_runtime`, `cluster_client`.
  Agent: `agent.begin_streaming`, `agent.emit_chunk_0`,
  `agent.emit_chunk_N_minus_1`, `agent.emit_final_result`. Runtime:
  `runtime.allocate_result_id`, `runtime.forward_chunk_0`,
  `runtime.forward_chunk_N_minus_1`, `runtime.forward_job_result`.
  Client: `client.start_assembly`, `client.append_chunk_0`,
  `client.append_chunk_N_minus_1`, `client.finalize`.
- **Edges:** `agent.begin_streaming -> runtime.allocate_result_id`
  (internal); each `agent.emit_chunk_K -> runtime.forward_chunk_K
  -> client.append_chunk_K` labeled
  `job.event { kind: result_chunk, body: { result_id, chunk_seq: K,
  encoding, more: true } }`. Final chunk edge labeled
  `… more: false`. Terminating edge
  `agent.emit_final_result -> runtime.forward_job_result ->
  client.finalize` labeled
  `job.result { final_status: success, result_id, result_size }`.
  A dashed annotation edge from
  `runtime.forward_job_result -> runtime.no_inline_result` (no-op
  node) labeled `MUST NOT mix with inline payload.result (§8.4)`.
- **PHP idiom note in caption:** "Client side consumes via
  `foreach ($client->subscribeJob(...) as $event) { match
  ($event->kind) { Kind::ResultChunk => …, … } }` (Phase 04 §2.1,
  §5). `chunk_seq` monotonicity asserted in
  `ResultChunkBody::fromArray()` — gap or out-of-order chunk throws
  `Arcp\Errors\InvalidRequestException`. Assembly buffer is a
  `string` accumulator (utf8) or a `Generator<string>` of decoded
  bytes (base64) — never a single `string` of binary blob in memory."
- **Caption §:** §8.4.

### (h) `progress-events.dot` — `kind: progress` interleaved with other kinds

- **Purpose:** Show §8.2.1 in context: progress events sit in the
  same `job.event` stream as `log`, `tool_call`, `tool_result`,
  `metric`, etc. Readers occasionally assume progress is a separate
  channel — the diagram nails the interleaving and the protocol's
  pass-through stance (advisory only).
- **Render:** `dot -Tsvg docs/diagrams/progress-events.dot -o docs/diagrams/progress-events.svg`.
- **Nodes:** lanes `cluster_agent`, `cluster_runtime`, `cluster_client`.
  A single timeline of event-emit nodes on each lane:
  `agent.emit_log_e1`, `agent.emit_tool_call_e2`,
  `agent.emit_progress_e3`, `agent.emit_tool_result_e4`,
  `agent.emit_progress_e5`, `agent.emit_metric_e6`,
  `agent.emit_progress_e7`. Matching `runtime.forward_*` and
  `client.recv_*` rows.
- **Edges:** seven cross-lane triples labeled with the wire shape,
  e.g. `agent.emit_progress_e3 -> runtime.forward_progress_e3 ->
  client.recv_progress_e3` labeled
  `job.event { kind: progress, body: { current: 47, total: 120,
  units: "files" } }`. The non-progress edges are styled the same
  as the worked example's "secondary wiring" (`penwidth=1.0`,
  `color="#94a3b8"`) so the progress edges visually dominate.
- **PHP idiom note in caption:** "Decoded by
  `Arcp\Job\Event\ProgressBody::fromArray()` (Phase 04 §2.1).
  Consumers render progress via a `match ($event->kind)
  { Kind::Progress => $ui->update(...) }`; the SDK takes no action
  (§8.2.1 'advisory'). The body's `total` is `?int` and `current`
  is `int >= 0` — both enforced at decode, not in the renderer."
- **Caption §:** §8.2.1, §8.2.

## 2. Build pipeline

A `composer diagrams` script in the root `composer.json` invokes
`bin/render-diagrams.sh`, which runs
`for f in docs/diagrams/*.dot; do dot -Tsvg "$f" -o "${f%.dot}.svg";
done`. The script `set -euo pipefail`s, uses `command -v dot ||
{ echo "install graphviz" >&2; exit 1; }`, and skips template files
matching `*template*.dot`. Both `.dot` sources and `.svg` outputs
are committed — the docs site at `arcp.dev` renders the SVGs
directly and contributors reading on GitHub without Graphviz still
see the pictures. CI runs `composer diagrams` and then `git diff
--exit-code docs/diagrams/*.svg` to verify regen drift; `dot -Tsvg`
is deterministic given the same Graphviz version, so the CI image
pins `graphviz` to a specific apt version (named in
`.github/workflows/diagrams.yml` once Phase 07 writes it).
Byte-for-byte equality is the cheapest assertion; if upstream
Graphviz changes attribute ordering across a minor version, the CI
pin absorbs the churn and bumps land as deliberate diagram
refreshes, not as silent doc-rot.
