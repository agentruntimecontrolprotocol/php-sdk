# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed — spec conformance (draft-arcp-1.1), breaking

- **Error taxonomy (§12)** — `ErrorCode` replaced with the canonical
  §12 set; gRPC-style codes and their exceptions removed, new
  `InvalidRequestException`, `InternalErrorException`,
  `TimeoutException`, `JobNotFoundException`,
  `AgentNotAvailableException`, `DuplicateKeyException`, and
  `ResumeWindowExpiredException` added. Tool-not-found now surfaces
  `AGENT_NOT_AVAILABLE`; resume buffer misses surface
  `RESUME_WINDOW_EXPIRED`. (#161, #150)
- **Envelope `event_seq` (§5/§8.3)** — optional session-scoped
  monotonically increasing sequence, stamped on outbound job events and
  results and round-tripped by the serializer. (#132, #152, partial #56)
- **Lenient decode (§5)** — unknown wire types decode to an ignorable
  `UnknownMessage` marker instead of throwing; dispatch loops log and
  skip them. (#133)
- **Wire-type renames (§6/§7)** — `session.open`→`session.hello`,
  `session.accepted`→`session.welcome`, `ping`/`pong`→
  `session.ping`/`session.pong` with §6.4 payloads, `ack`→`session.ack`
  with `last_processed_seq`, `resume`→`session.resume`,
  `cancel`→`job.cancel` with `{job_id}`, `subscribe`→`job.subscribe`,
  `subscribe.accepted`→`job.subscribed` with the §7.6 payload,
  `unsubscribe`→`job.unsubscribe`. Classes renamed to match; subscriptions
  are now job-scoped. (#121, #122, #127, #128, #131, #140, #138, #151,
  #149)
- **Job lifecycle (§7/§8)** — new `job.submit`, `job.event`,
  `job.result`, and `job.error` messages replace
  `tool.invoke`/`tool.result`/`tool.error` and
  `job.completed`/`job.failed`/`job.started`; `job.accepted` carries the
  full §7.1 payload; `JobState` matches §7.3 (`pending`, `running`,
  `success`, `error`, `cancelled`, `timed_out`) and `max_runtime_sec`
  expiry terminates as `timed_out`/`TIMEOUT`. (#137, #134, #135)

- **Capabilities / peer info / auth (§6.1–§6.2)** — `Capabilities`
  reshaped to `{encodings, features, agents?}` with intersection
  semantics on negotiate; `PeerInfo` uses `name`/`version`; only the
  `bearer` scheme (plus the SDK's `anonymous` extension) is accepted,
  anything else is `UNAUTHENTICATED`. (#141, #148, #142)
- **Token resume (§6.3)** — `session.welcome` carries `resume_token`
  (rotated per welcome), `resume_window_sec`, and
  `heartbeat_interval_sec` when negotiated; reconnect via
  `session.hello {resume_token, last_event_seq}` reattaches the parked
  session and replays buffered events; unknown/expired tokens and
  uncovered sequences answer `RESUME_WINDOW_EXPIRED`; the legacy
  `session.resume`/`after_message_id` machinery is removed. (#123,
  #124, #125, #126, #55)
- **Heartbeats & acks (§6.4–§6.5)** — ping/pong/ack are neither
  sequenced nor buffered; `session.ack` releases buffered events at or
  below `last_processed_seq`. (#145, #146)
- **Graceful close (§6.7)** — `session.close` is acknowledged with the
  new `session.closed` before teardown and in-flight jobs keep running
  (resumable for the resume window). (#57, #129, #130)
- **Job listing (§6.6)** — `session.jobs` entries carry `lease`,
  `parent_job_id`, and `last_event_seq`. (#143)
- **Job events (§8.2)** — `job.progress`, `log`, `metric`, and
  `event.emit` folded into `job.event` kinds `progress` (§8.2.1 body
  `{current, total?, units?, message?}`), `log`, `metric`, and
  `status`. (#63, #147)
- **Result streaming (§8.4)** — chunks ride as `job.event` kind
  `result_chunk`; the runtime mints `result_id`, the terminal
  `job.result` carries `final_status` + `result_id` + `result_size`,
  inline/chunk mixing and unterminated streams are rejected, and
  divergent chunk retransmissions raise while byte-identical ones are
  tolerated. (#153, #154, #64)
- **Idempotency (§7.2)** — acceptance-time claims with a canonical
  request fingerprint; identical retries replay the original
  `job.accepted` (budget captured at acceptance) plus the terminal
  outcome; conflicting reuse returns `DUPLICATE_KEY`. (#59, #136)
- **`nack` removed (§12)** — command rejections are correlated
  top-level `job.error` envelopes echoing the request envelope id.

See `UPGRADE.md` → “Unreleased — spec conformance” for the full
old→new wire-type table and migration notes.

## [1.1.0] - 2026-05-22

### Added

- ARCP v1.1 feature coverage for `session.list_jobs` / `session.jobs`,
  versioned `name@version` tool resolution, `job.result_chunk`, and
  `cost.budget`, `model.use`, and provisioned credential enforcement.
- `Arcp\Errors\BudgetExhaustedException` and
  `Arcp\Errors\AgentVersionNotAvailableException` mapped to their v1.1
  canonical wire codes.
- `Arcp\Errors\ARCPExceptionInterface` — root marker for every exception
  the SDK can throw. Implemented by the existing abstract
  `Arcp\Errors\ARCPException` (no migration needed for existing catch
  blocks). See `UPGRADE.md`.
- `Arcp\Errors\TransportClosedException` — typed replacement for the
  bare `\RuntimeException('... closed')` previously thrown by stdio,
  memory, and WebSocket transports. Maps to `ErrorCode::Unavailable`.
- `Arcp\Internal\...` namespace housing the runtime and client
  collaborators that were split out of `ARCPRuntime` / `ARCPClient` /
  `EnvelopeSerializer`. Marked `@internal`; not part of the BC promise.
- Toolchain: `rector/rector`, `infection/infection`, `tools/size-check.php`
  gate enforcing PHP_SDK_GUIDE §14 hard limits. New composer scripts
  `rector`, `rector:fix`, `infection`, `size-check`, `audit`, `all`.

### Changed

- `Envelope`, `Id`, and `ErrorPayload` validation paths now throw
  `Arcp\Errors\InvalidArgumentException` instead of the SPL
  `\InvalidArgumentException`. Migration in `UPGRADE.md`.
- Every src/ file is wrapped to ≤ 100 chars, every method body ≤ 30
  lines, every file ≤ 400 lines, every class ≤ 300 lines.

### Fixed

- Psalm `errorLevel: 1` now reports zero errors on test code.

## [0.1.0] - 2026-05-10

### Added

- Initial reference SDK release aligned with ARCP protocol v1.1 (see README status).

[Unreleased]: https://github.com/agentruntimecontrolprotocol/php-sdk/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/agentruntimecontrolprotocol/php-sdk/compare/v0.1.0...v1.1.0
[0.1.0]: https://github.com/agentruntimecontrolprotocol/php-sdk/releases/tag/v0.1.0
