# Contributing to arcp/arcp

Thanks for your interest in improving the PHP SDK for ARCP. This
document covers how to report issues, propose changes, and get a change merged.

By participating you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).

## Where changes belong

ARCP is two things in two places, and a change belongs to exactly one of them:

- **The protocol** — the wire format, message semantics, lease rules, error
  taxonomy, feature flags. These live in the
  [specification repository](https://github.com/agentruntimecontrolprotocol/spec).
  If your idea changes what goes *on the wire* or what a conformant runtime must
  do, it is a spec change — open it there, not here. This SDK implements the
  spec; it does not define it.
- **This SDK** — how the protocol is expressed idiomatically in PHP:
  bugs, ergonomics, performance, missing-but-specified features, docs, tests.
  Those belong here.

When in doubt, open an issue here and we'll redirect if it's really a protocol
question.

## The golden rule: conform, don't extend

A change to this SDK must keep it a faithful client of
[ARCP v1.1 (draft)](https://github.com/agentruntimecontrolprotocol/spec/blob/main/docs/draft-arcp-1.1.md).
Concretely:

- **Don't invent wire behavior.** No envelope fields, event kinds, error codes,
  or feature flags that the spec doesn't define. If you need one, it's a spec
  proposal first.
- **Negotiate honestly.** Only advertise a feature flag in `session.hello` once
  the SDK actually implements it. The feature matrix in the README must match
  what the code negotiates — a row marked `Supported` is a promise.
- **Respect the semantics.** Sequence numbers stay gap-free and monotonic;
  `LEASE_EXPIRED` and `BUDGET_EXHAUSTED` stay non-retryable; the effective
  feature set is the intersection of client and runtime advertisements. Tests
  must not paper over a semantic the spec requires.
- **Stay layered.** This SDK controls runtimes. It does not expose tools (that's
  MCP) or export telemetry (that's OpenTelemetry). PRs that blur those layers
  will be asked to move the logic out.

## Reporting bugs

Open an issue with: the SDK version and PHP version, the runtime you
connected to, a minimal reproduction (the smallest program that triggers it),
what you expected, and what happened. A failing test is the best possible bug
report. Wire-level traces (the envelopes exchanged) help enormously for protocol
behavior — redact any `auth.token` or provisioned-credential `value` first.

## Proposing a change

For anything beyond a small fix, open an issue describing the problem before
writing code, so we can agree on the approach. Small, focused PRs review faster
than large ones; if a change is big, say so early and we'll help break it down.

## Development setup

You'll need PHP 8.4 or newer with the `pdo_sqlite`, `mbstring`, and
`json` extensions, plus Composer 2.x. Clone the repository and install
the dev dependencies with Composer; everything else (PHPUnit, PHPStan,
Psalm, PHP-CS-Fixer, Rector) is pulled in as a dev requirement.

```sh
git clone https://github.com/agentruntimecontrolprotocol/php-sdk.git
cd php-sdk
composer install
```

The runtime CLI lives at `bin/arcp` and is registered as a Composer
binary, so `vendor/bin/arcp` is available inside any package that
depends on this SDK during development.

## Tests and conformance

Two layers must pass before a PR merges:

- **Unit tests** — this SDK's own suite:

  ```sh
  composer test
  ```

- **Conformance** — the SDK's behavior against the reference runtime. New
  protocol-facing code (session negotiation, event sequencing, lease handling,
  error mapping) needs a test that exercises the real exchange, not a mock that
  assumes the answer. Most conformance scenarios run in-process against
  `Arcp\Runtime\ARCPRuntime` over `MemoryTransport`; to exercise a
  real wire transport, start a runtime with `bin/arcp serve --host
  127.0.0.1 --port 8765` and point your test client at
  `ws://127.0.0.1:8765/`. The full local gate (`composer gates`) runs
  formatting, PHPStan, Psalm, and PHPUnit together.

CI runs both on every PR. A PR that changes which feature flags the SDK
negotiates must also update the README feature matrix in the same change.

## Coding standards

This repo uses PHP-CS-Fixer for formatting, PHPStan and Psalm for
static analysis, and Rector for automated refactors. Run them via the
Composer scripts:

```sh
composer format   # php-cs-fixer fix
composer lint     # php-cs-fixer fix --dry-run --diff
composer stan     # phpstan analyze --memory-limit=512M
composer psalm    # psalm --no-cache
composer rector   # rector process --dry-run
```

Match the surrounding code. Public API changes need doc comments and an entry in
the changelog. Prefer clarity over cleverness in a library others build on.

## Commit and pull-request conventions

- Write focused commits with present-tense, imperative subjects
  (`add result_chunk reassembly`, not `added` / `adds`).
- Reference the issue a PR closes (`Closes #123`).
- Keep the PR description honest about scope and any spec sections touched.
- Rebase on the default branch and ensure CI is green before requesting review.
- Sign off your commits to certify the [Developer Certificate of Origin](https://developercertificate.org/):

  ```sh
  git commit -s -m "your message"
  ```

## Releases

Releases are cut by maintainers. The package is published to
[Packagist](https://packagist.org/packages/arcp/arcp); pushing a
`v*` tag (for example `v1.1.0`) triggers the `publish` GitHub Actions
workflow, which validates the Composer metadata, builds the archive,
and notifies Packagist. The SDK is versioned with
semantic versioning independently of the protocol version it speaks; a protocol
version bump is noted in the changelog when the negotiated ARCP version changes.

## License

By contributing, you agree that your contributions are licensed under the
project's [Apache-2.0](LICENSE) license.
