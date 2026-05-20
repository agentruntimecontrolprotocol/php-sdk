# Errors (§12)

Every exception thrown by the SDK implements
`Arcp\Errors\ARCPExceptionInterface`.

## Codes

Canonical codes live in `Arcp\Errors\ErrorCode`. v1.1 additions include:

| Code | Exception |
| --- | --- |
| `LEASE_EXPIRED` | `LeaseExpiredException` |
| `BUDGET_EXHAUSTED` | `BudgetExhaustedException` |
| `AGENT_VERSION_NOT_AVAILABLE` | `AgentVersionNotAvailableException` |

## Wire shape

Errors travel as `ErrorPayload`:

```json
{
  "code": "PERMISSION_DENIED",
  "message": "lease expired",
  "retryable": false,
  "details": {"lease_id": "lease_..."}
}
```

## Throwing from a tool

Throw an `ARCPException` subclass from a `ToolHandler`. The runtime maps
it to `tool.error` and `job.failed`.

```php
throw new InvalidArgumentException('missing prompt');
```

## Catching on the client

```php
try {
    $client->invokeTool('search', ['q' => 'php']);
} catch (ARCPExceptionInterface $e) {
    if ($e->isRetryable()) {
        // retry with the same idempotency key
    }
}
```

## Session-level errors

Handshake failures arrive as `session.rejected` or
`session.unauthenticated` and are raised by `ARCPClient::open()`.

## Errors on a `tool_result`

Direct tool failures are surfaced as typed exceptions by `ErrorMapper`.

## Lease violations look like `tool_result.error`

Permission and lease failures inside a tool become terminal tool errors.

## Retry guidance

Retry only when `ARCPExceptionInterface::isRetryable()` is true, and use
`IdempotencyKey` for mutating calls.

## Adding context to a client-side rethrow

Wrap the SDK exception in your application exception, but preserve the
previous exception and its `code()`.

## Runnable example

See `samples/cost_budget/` and `tests/Unit/ErrorsTest.php`.
