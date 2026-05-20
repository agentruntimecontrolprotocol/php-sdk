# Jobs (§7)

In PHP, direct tool invocation is represented by `ToolInvoke` and
handled by `ToolInvocationHandler`.

## States (§7.3)

`JobState` includes accepted, queued, running, blocked, paused,
completed, failed, and cancelled.

## Client side

```php
$result = $client->invokeTool('planner@1.0.0', ['task' => 'write']);
```

## Runtime side

```php
$runtime->registerTool('planner', $handler);
$runtime->registerToolVersion('planner', '1.0.0', $handler);
$runtime->setDefaultToolVersion('planner', '1.0.0');
```

## `SubmitOptions`

PHP uses method parameters and typed IDs rather than a single submit
options object:

```php
$client->invokeTool('echo', [], traceId: TraceId::random(), idempotencyKey: $key);
```

## Idempotency (§7.2)

Pass `IdempotencyKey` to `invokeTool()` for mutating operations.

## Cancellation (§7.4)

Call `ARCPClient::cancelJob($jobId)`. Tool handlers should accept and
observe `Amp\Cancellation`.

## Timeouts

Pass `$deadlineSeconds` to `invokeTool()` to bound the await.

## Cost budgets (v1.1, §9.6)

Pass `['lease' => ['cost.budget' => ['USD:1.00']]]`; cost metrics
decrement the counter and can raise `BudgetExhaustedException`.

## Agent versions (v1.1, §7.5)

Use `name@version` in tool names. Missing versions raise
`AgentVersionNotAvailableException`.

## What the runtime guarantees

The runtime emits accepted/started/terminal job messages, maps typed
exceptions to errors, and removes terminal jobs from the live list.

## Runnable examples

See `samples/agent_versions/`, `samples/list_jobs/`, and
`tests/Integration/JobLifecycleTest.php`.
