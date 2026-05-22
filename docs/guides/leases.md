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

## Model-use leases (v1.1, §9.7)

`ModelUse` carries allow-list patterns such as `openai/gpt-4o`,
`anthropic/*`, or `*`. Tool code can enforce the active job lease before
calling an upstream model:

```php
$ctx->assertModelAllowed('anthropic/claude-3-5-sonnet');
```

Child leases must stay within the parent model set. `LeaseManager`
raises `LeaseSubsetViolationException` when a child expands either
`model.use` or `cost.budget`.

## Provisioned credentials (v1.1, §9.8)

Configure `ARCPRuntime` with a `CredentialProvisioner` to mint
short-lived upstream credentials after the job lease is finalized:

```php
$runtime = new ARCPRuntime(
    authRouter: $auth,
    credentialProvisioner: $provisioner,
);
```

Clients opt in during the handshake with:

```php
new Capabilities(
    anonymous: true,
    features: ['provisioned_credentials', 'model.use'],
);
```

When the tool invocation carries `lease.model.use` or
`lease.cost.budget`, the runtime includes a `credentials` array in the
direct `job.accepted` payload. Credential values are redacted in the
event log and subscription delivery, and the runtime revokes outstanding
credentials on success, failure, or cancellation.

## Hand-written validation

Use typed exceptions from `Arcp\Errors` so clients can branch on
canonical error codes.

## Runnable examples

See `samples/leases/`, `samples/lease_revocation/`, and
`samples/cost_budget/`, and `samples/provisioned_credentials/`.
