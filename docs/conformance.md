# Conformance

The PHP SDK targets ARCP v1.1.

## v1.1 coverage

| Area | Status |
| --- | --- |
| Envelope JSON and typed message catalog | implemented |
| Session open/accepted/rejected/close | implemented |
| Ping/pong, ack, resume replay | implemented |
| `session.list_jobs` / `session.jobs` | implemented |
| Tool invocation and job lifecycle | implemented |
| Agent `name@version` resolution | implemented |
| Progress, streams, and `job.result_chunk` | implemented |
| Permissions and leases | implemented |
| `cost.budget` counters | implemented |
| `model.use` leases | implemented |
| Provisioned credentials | implemented |
| `LEASE_SUBSET_VIOLATION` | implemented |
| Artifacts | implemented |
| Subscriptions and backfill | implemented |
| Vendor extensions | implemented |

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
