# ARCP PHP SDK documentation

Reference docs for the ARCP PHP SDK. The top-level
[README](../README.md) is the front door; these pages go deeper into
the runtime, client, transports, and protocol features.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="./diagrams/architecture-dark.svg">
  <img alt="ARCP PHP SDK architecture" src="./diagrams/architecture-light.svg">
</picture>

## Start here

- [Getting started](./getting-started.md) - install, create an in-process runtime + client, run the checks.
- [Architecture](./architecture.md) - how the PHP namespaces fit together.
- [Transports](./transports.md) - WebSocket, stdio, and memory transports.
- [CLI](./cli.md) - the `bin/arcp` command shipped by `arcp/sdk`.

## Guides (one per spec section)

| Page | Spec |
| --- | --- |
| [Sessions](./guides/sessions.md) | §6 |
| [Resume](./guides/resume.md) | §6.3 |
| [Authentication](./guides/auth.md) | §6.1 |
| [Jobs](./guides/jobs.md) | §7 |
| [Job events](./guides/job-events.md) | §8 |
| [Leases](./guides/leases.md) | §9 |
| [Delegation](./guides/delegation.md) | §10 |
| [Observability](./guides/observability.md) | §11 |
| [Errors](./guides/errors.md) | §12 |
| [Vendor extensions](./guides/vendor-extensions.md) | §15 |

## PHP Namespaces

The TypeScript SDK has package-specific docs because its code is split
across multiple npm packages. PHP ships as one Composer package,
`arcp/sdk`, so this documentation follows the namespace layout under
`src/`.

| Namespace | Purpose |
| --- | --- |
| `Arcp\Envelope`, `Arcp\Json` | Wire envelope model and serialization |
| `Arcp\Messages` | Typed protocol payloads |
| `Arcp\Ids` | Typed IDs (`SessionId`, `JobId`, `TraceId`, etc.) |
| `Arcp\Errors` | Canonical error codes and exceptions |
| `Arcp\Client` | `ARCPClient` and client-side handlers |
| `Arcp\Runtime` | `ARCPRuntime`, jobs, leases, artifacts, subscriptions |
| `Arcp\Transport` | WebSocket, stdio, and in-memory transports |
| `Arcp\Auth` | Built-in auth schemes and `AuthRouter` |
| `Arcp\Store` | Event log and idempotency persistence |
| `Arcp\Clock` | Time source abstraction for deterministic tests |
| `Arcp\Extensions` | Vendor extension classification |
| `Arcp\Cli` | `bin/arcp` commands |

## Reference

- [Recipes](./recipes.md) - copy-paste PHP solutions to common problems.
- [Conformance](./conformance.md) - implemented and deferred protocol surfaces.
- [Troubleshooting](./troubleshooting.md) - error codes and fixes.

## Diagrams

The diagram above is generated from Graphviz. Source files and the
authoring guide live in [`./diagrams/`](./diagrams/).
