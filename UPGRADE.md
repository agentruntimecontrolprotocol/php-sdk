# Upgrade Guide

The ARCP PHP SDK follows Semantic Versioning. Every breaking change
ships on a major bump, with a deprecation cycle of at least one minor
release where feasible.

This document covers upgrades that need user attention. The full release
history lives in `CHANGELOG.md`.

---

## v1.x — internal SDK refactor

The v1.x line introduces no breaking changes for code that uses the
documented public surface (`Arcp\Client\ARCPClient`, `Arcp\Runtime\ARCPRuntime`,
the `Arcp\Messages\…` value objects, the `Arcp\Errors\…` exception
hierarchy, and the `Arcp\Transport\Transport` adapter contract).

What changed:

### Additive — new root exception marker

`Arcp\Errors\ARCPExceptionInterface` is the new root marker for every
exception the SDK can throw. Existing `catch (\Arcp\Errors\ARCPException $e)`
blocks continue to work — the abstract `ARCPException` now implements
the interface but its base class and constructor are unchanged. The
recommended pattern going forward:

```php
try {
    $client->invokeTool('search', ['q' => $query]);
} catch (\Arcp\Errors\ARCPExceptionInterface $e) {
    $logger->warning('arcp call failed', [
        'code' => $e->code()->value,
        'retryable' => $e->isRetryable(),
    ]);
}
```

### Additive — `TransportClosedException`

Three transports (`StdioTransport`, `MemoryTransport`, `WebSocketTransport`)
previously threw bare `\RuntimeException('… closed')` when called after
close. They now throw `Arcp\Errors\TransportClosedException`, which
descends from `Arcp\Errors\ARCPException` (which extends
`\RuntimeException`). Existing `catch (\RuntimeException $e)` blocks
keep working; new code can catch the typed exception or
`ARCPExceptionInterface`.

### Internal — `Arcp\Internal\…` namespace

Runtime and client internals (`HandshakeNegotiator`,
`ToolInvocationHandler`, `SubscriptionRouter`, `LifecycleHandler`,
`ArtifactDispatcher`, `ResponseRouter`, etc.) live under
`Arcp\Internal\…`. They are marked `@internal` and are NOT part of the
backward-compatibility promise. Application code should depend only on
`Arcp\Client\ARCPClient` and `Arcp\Runtime\ARCPRuntime`.

### Internal — validation exceptions

A handful of internal validation paths in `Envelope`, `Id`, and
`ErrorPayload` previously threw the SPL `\InvalidArgumentException`.
They now throw `Arcp\Errors\InvalidArgumentException` (which extends
SPL's `\RuntimeException` via `ARCPException`).

If you have tests that explicitly assert the SPL type, update them to
the local type:

```diff
- $this->expectException(\InvalidArgumentException::class);
+ $this->expectException(\Arcp\Errors\InvalidArgumentException::class);
```

If you catch by `\Throwable` or by the local
`Arcp\Errors\InvalidArgumentException` already, no action needed.

### Tooling — Rector, Infection, size-check

New dev dependencies (`rector/rector`, `infection/infection`) and a
new `tools/size-check.php` gate enforce PHP_SDK_GUIDE §14 limits.
`composer all` runs every check; CI configurations should adopt it.
