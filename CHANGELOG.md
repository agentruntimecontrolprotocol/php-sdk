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
