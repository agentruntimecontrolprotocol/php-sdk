# Conformance

The PHP SDK targets ARCP v1.1.

## v1.1 coverage

Status legend: **Full** — matches the v1.1 wire shape; **Partial** — works
but still diverges from the spec wire shape (tracked by the linked issues).

| Area | Status | Notes |
| --- | --- | --- |
| Envelope JSON and typed message catalog | Partial | no top-level `event_seq` yet (#132, #152) |
| Session hello/welcome/rejected/close | Partial | uses `session.open`/`session.accepted` not `session.hello`/`session.welcome`; no `session.closed` ack (#121, #122, #123, #130) |
| Ping/pong, ack, resume | Partial | `ping`/`pong` not `session.ping`/`session.pong`; `ack` is advisory-only; resume uses `after_message_id` not `session.resume` + rotating token (#127, #128, #146, #55, #125) |
| `session.list_jobs` / `session.jobs` | Partial | entries omit `lease`/`parent_job_id`/`last_event_seq`; credentials redacted from the inventory (#143) |
| Tool invocation and job lifecycle | Partial | submission uses `tool.invoke` not `job.submit`; terminal states are `completed`/`failed` not `success`/`error`/`timed_out` (#134, #137) |
| Agent `name@version` resolution | Full | deterministic resolution; ambiguous unversioned names are rejected |
| Progress, streams, and `job.result_chunk` | Partial | progress body uses `percent` not `current`/`total`; inline/chunk mixing not yet prevented (#63, #147, #64, #153, #154) |
| Permissions and leases | Partial | `expires_at` UTC/future validation and runtime expiry enforcement pending (#60, #156) |
| `cost.budget` counters | Partial | negative metrics rejected and exact-zero allowed; no pre-dispatch budget check (#158) |
| `model.use` leases | Full | pattern grammar matches the spec examples |
| Provisioned credentials | Partial | per-job scoping and retried revocation in place; no startup revocation replay (#160) |
| `LEASE_SUBSET_VIOLATION` | Full | model.use, cost.budget, and `expires_at` containment enforced |
| Artifacts | Full | `ref()`/`fetch()` agree on expiry |
| Subscriptions and backfill | Partial | uses `subscribe`/`subscribe.accepted` not `job.subscribe`/`job.subscribed`; principal authorization pending (#138, #151, #139) |
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
