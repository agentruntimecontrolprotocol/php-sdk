# Conformance

The PHP SDK targets ARCP v1.1.

## v1.1 coverage

Status legend: **Full** — matches the v1.1 wire shape; **Partial** — works
but still diverges from the spec wire shape (tracked by the linked issues).

| Area | Status | Notes |
| --- | --- | --- |
| Envelope JSON and typed message catalog | Full | top-level `event_seq` on sequenced job messages; lenient unknown-type decode |
| Session hello/welcome/closed (§6.1–§6.2, §6.7) | Full | `{encodings, features, agents}` capabilities with intersection semantics; bearer/anonymous auth; `session.closed` ack leaves jobs running |
| Ping/pong, ack, resume (§6.3–§6.5) | Full | heartbeats unsequenced and unbuffered; `session.ack` releases the buffer; token resume via `session.hello {resume_token, last_event_seq}` with rotation and `RESUME_WINDOW_EXPIRED` |
| `session.list_jobs` / `session.jobs` (§6.6) | Full | entries carry `lease`/`parent_job_id`/`last_event_seq`; credentials redacted from the inventory |
| Job submission and lifecycle (§7.1–§7.4) | Full | `job.submit`/`job.accepted`/`job.result`/`job.error`; §7.3 terminal states |
| Idempotency (§7.2) | Full | canonical fingerprint; identical retry replays the original `job.accepted`; conflicting reuse returns `DUPLICATE_KEY` |
| Agent `name@version` resolution (§7.5) | Full | deterministic resolution; ambiguous unversioned names are rejected |
| Job events (§8.1–§8.4) | Full | `progress`/`log`/`metric`/`status`/`result_chunk` ride as `job.event` kinds; streamed results terminate with `job.result {final_status, result_id}`; inline/chunk mixing rejected |
| Permissions and leases | Partial | `expires_at` UTC/future validation and runtime expiry enforcement pending (#60, #156) |
| `cost.budget` counters | Partial | negative metrics rejected and exact-zero allowed; no pre-dispatch budget check (#158) |
| `model.use` leases | Full | pattern grammar matches the spec examples |
| Provisioned credentials | Partial | per-job scoping and retried revocation in place; no startup revocation replay (#160) |
| `LEASE_SUBSET_VIOLATION` | Full | model.use, cost.budget, and `expires_at` containment enforced |
| Artifacts | Full | `ref()`/`fetch()` agree on expiry (SDK extension surface) |
| Subscriptions and backfill (§7.6) | Partial | `job.subscribe`/`job.subscribed`/`job.unsubscribe`; cross-principal authorization policy pending (#139) |
| Error taxonomy (§12) | Full | command rejections are correlated top-level `job.error`; no generic `nack` |
| Vendor extensions | Full | core-type classification and `x-` rejection match the spec |

## v1.1 features

The v1.1 PHP-specific additions are covered by unit and integration
tests in:

- `tests/Unit/Runtime/V11FeaturesTest.php`
- `tests/Unit/Runtime/ModelUseTest.php`
- `tests/Integration/CredentialLifecycleTest.php`
- `tests/Integration/JobLifecycleTest.php`
- `tests/Unit/MessageCatalogRoundTripTest.php`
- `tests/Unit/ErrorsTest.php`

## How conformance is tested

Run the project gates:

```sh
composer gates
```

This runs formatting, PHPStan, Psalm, and PHPUnit.

## Reporting a deviation

Open a GitHub issue with:

- the envelope JSON,
- the expected ARCP behavior,
- the observed PHP SDK behavior,
- the spec section.
