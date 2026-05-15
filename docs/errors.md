# Errors

Every exception the PHP SDK throws implements
`Arcp\Errors\ARCPExceptionInterface`. Catch that interface to handle
"anything from this library"; catch a specific subclass for typed
handling.

## Hierarchy

```
\Throwable
  \RuntimeException
    Arcp\Errors\ARCPException                  (abstract)
      Arcp\Errors\AbortedException             (ABORTED, retryable)
      Arcp\Errors\AlreadyExistsException       (ALREADY_EXISTS)
      Arcp\Errors\BackpressureOverflowException(BACKPRESSURE_OVERFLOW)
      Arcp\Errors\CancelledException           (CANCELLED)
      Arcp\Errors\DataLossException            (DATA_LOSS)
      Arcp\Errors\DeadlineExceededException    (DEADLINE_EXCEEDED, retryable)
      Arcp\Errors\FailedPreconditionException  (FAILED_PRECONDITION)
      Arcp\Errors\HeartbeatLostException       (HEARTBEAT_LOST)
      Arcp\Errors\InternalException            (INTERNAL, retryable)
      Arcp\Errors\InvalidArgumentException     (INVALID_ARGUMENT)
      Arcp\Errors\LeaseExpiredException        (LEASE_EXPIRED)
      Arcp\Errors\LeaseRevokedException        (LEASE_REVOKED)
      Arcp\Errors\NotFoundException            (NOT_FOUND)
      Arcp\Errors\OutOfRangeException          (OUT_OF_RANGE)
      Arcp\Errors\PermissionDeniedException    (PERMISSION_DENIED)
      Arcp\Errors\ResourceExhaustedException   (RESOURCE_EXHAUSTED, retryable)
      Arcp\Errors\TransportClosedException     (UNAVAILABLE)
      Arcp\Errors\UnauthenticatedException     (UNAUTHENTICATED)
      Arcp\Errors\UnavailableException         (UNAVAILABLE, retryable)
      Arcp\Errors\UnimplementedException       (UNIMPLEMENTED)
      Arcp\Errors\UnknownException             (UNKNOWN)
```

## Retryability

Each `ErrorCode` has a default retryability per RFC §18.3, exposed via
`ErrorCode::defaultRetryable()`. The sender can override the default
by setting `$retryable` on the exception (the wire envelope carries it).

```php
try {
    $result = $client->invokeTool('search', ['q' => $query]);
} catch (\Arcp\Errors\ARCPExceptionInterface $e) {
    if ($e->isRetryable()) {
        $this->scheduleRetry($e);
    } else {
        $this->surfaceToUser($e);
    }
}
```

## Code-driven dispatch

`ErrorCode` is an enum. Use it in a `match` for code-level branching:

```php
catch (\Arcp\Errors\ARCPExceptionInterface $e) {
    match ($e->code()) {
        ErrorCode::PermissionDenied => $this->renewLease(),
        ErrorCode::Cancelled,
        ErrorCode::DeadlineExceeded => $this->logAbandoned(),
        default => $this->surface($e),
    };
}
```

## Wire shape

The serialized envelope follows RFC §18.1. `Arcp\Errors\ErrorPayload`
is the value object that round-trips through `EnvelopeSerializer`:

```json
{
  "code": "PERMISSION_DENIED",
  "message": "lease expired",
  "retryable": false,
  "details": {"lease_id": "lease_..."},
  "trace_id": "trace_..."
}
```

Non-canonical codes (e.g. `arcpx.acme.QUOTA_EXCEEDED`) travel as their
literal strings; consumers can map them via
`ErrorPayload::canonical()` (returns the canonical
`ErrorCode|null`) or `ErrorPayload::isNamespaced()`.

## Things not to do

- Don't throw `\Exception`, `\RuntimeException`, or
  `\InvalidArgumentException` from library code — use the typed
  exceptions above. The Phase 5 refactor removed the last library
  call sites; the size-check gate prevents regressions.
- Don't swallow `ARCPExceptionInterface` in a global try/catch;
  preserve the type so callers can branch on `code()`.
