# Leases (§9)

The runtime tracks granted leases through `LeaseManager`.

## Shape

`LeaseGranted` carries `lease_id`, permission, resource, operation, and
expiry.

## Example

```php
$leaseId = $ctx->requestPermission('repo.write', 'repo:arcp', 'apply_patch');
```

## Glob matching (§9.2)

Host applications define permission/resource naming. `LeaseManager`
checks exact scope equality through `LeaseScope`.

## Canonicalization (§14)

Canonicalize resources before requesting or checking leases so equivalent
paths do not produce different scopes.

## Immutability at submit

Treat submitted lease constraints and budget counters as immutable job
inputs.

## Enforcement points

Enforce before side effects and when emitting cost metrics.

## Subset validation

`CostBudget::containsSubset()` checks child budget <= parent remaining
for the v1.1 budget capability.

## Expiration (v1.1, §9.5)

Expired leases raise `LeaseExpiredException`.

## Budgets (v1.1, §9.6)

`CostBudget` parses `currency:decimal` strings and decrements counters
from `cost.*` metrics.

## Hand-written validation

Use typed exceptions from `Arcp\Errors` so clients can branch on
canonical error codes.

## Runnable examples

See `samples/leases/`, `samples/lease_revocation/`, and
`samples/cost_budget/`.
