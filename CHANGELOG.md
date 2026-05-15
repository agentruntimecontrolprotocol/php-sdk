# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Arcp\Errors\ARCPExceptionInterface` — root marker for every exception
  the SDK can throw. Implemented by the existing abstract
  `Arcp\Errors\ARCPException` (no migration needed for existing catch
  blocks). See `UPGRADE.md`.
- `Arcp\Errors\TransportClosedException` — typed replacement for the
  bare `\RuntimeException('… closed')` previously thrown by stdio,
  memory, and WebSocket transports. Maps to `ErrorCode::Unavailable`.
- `Arcp\Internal\…` namespace housing the runtime and client
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

- Initial reference SDK release aligned with ARCP protocol v1.0 (see README status).

