# Recipes

Composed ARCP flows that combine multiple protocol features around a
realistic agent workload. These PHP ports mirror the TypeScript recipe
set while keeping provider calls behind small placeholders.

| Recipe | Shape |
| --- | --- |
| [`email-vendor-leases/`](./email-vendor-leases) | Triage agent with read-only email leases and vendor events. |
| [`mcp-skill/`](./mcp-skill) | MCP bridge exposing a long-lived ARCP planner as one MCP tool. |
| [`multi-agent-budget/`](./multi-agent-budget) | Planner delegates budget slices to workers and records spend. |
| [`stream-resume/`](./stream-resume) | Streaming result chunks survive reconnect via resume replay. |

Each recipe has a `server.php` for the runtime-side shape and, where a
client matters, a `client.php` for the caller flow.
