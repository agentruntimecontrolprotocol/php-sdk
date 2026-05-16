# REFACTOR NOTES

Working journal of deliberate deviations from the PHP_SDK_GUIDE.md §14
size-check limits. Each entry lists the file/symbol, the limit being
suppressed, and the BC or protocol reason that prevents a clean collapse.

## Phase 7 size-check suppressions (2026-05-15)

The `php tools/size-check.php src` tool exits clean. The following symbols
carry `@size-check-suppress <reason>` docblocks because either (a) they are
part of the public API and a positional-signature change would break BC,
or (b) they are param-object DTOs whose whole purpose is to bundle the very
fields the size limit would otherwise reject.

### Public-API BC suppressions

These keep existing user code (positional & named arguments) working. A
parameter-object successor is provided where relevant (`ARCPClient::withConfig`,
`ARCPRuntime::withConfig`).

- `src/Client/ARCPClient.php` — `__construct` (7 params; superseded by
  `Arcp\Client\ClientConfig` + `ARCPClient::withConfig`).
- `src/Client/ARCPClient.php` — `open` (5 params; mirrors RFC §8.3
  `session.open` wire shape).
- `src/Client/ARCPClient.php` — `invokeTool` (7 params; tool.invoke options
  are RFC §10 wire fields).
- `src/Client/ARCPClient.php` — `subscribe` (5 params; subscribe is the
  RFC §13 entry-point).
- `src/Runtime/ARCPRuntime.php` — `__construct` (9 params; superseded by
  `Arcp\Runtime\RuntimeConfig` + `ARCPRuntime::withConfig`).
- `src/Runtime/JobContext.php` — `requestPermission` (6 params; protocol-level
  permission request fields are part of the API).
- `src/Runtime/JobContext.php` — `requestHumanInput` (6 params; mirrors
  RFC §12.1 `human.input.request` shape).
- `src/Runtime/JobContext.php` — `requestHumanChoice` (5 params; mirrors
  RFC §12.1 `human.choice.request` shape).

### Parameter-object DTO suppressions

These types exist *to* bundle a fixed wire/protocol field set, so the
constructor parameter list is the data they carry. Collapsing them further
would require nested DTOs that double the indirection without simplifying
the callers.

- `src/Client/ClientConfig.php` — `__construct` (7 params; bundle of
  ARCPClient deps).
- `src/Internal/Client/ResponseRouterDeps.php` — `__construct` (7 params;
  bundle of ResponseRouter collaborators).
- `src/Runtime/RuntimeConfig.php` — `__construct` (9 params; bundle of
  ARCPRuntime deps).
- `src/Runtime/SubscriptionFilter.php` — `__construct` (7 params; mirrors
  RFC §13.2 filter fields).
