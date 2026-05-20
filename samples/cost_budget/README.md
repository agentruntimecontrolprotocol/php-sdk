# cost_budget

Demonstrates ARCP v1.1 `cost.budget`: invocation arguments grant a
per-currency counter, tool-emitted `cost.*` metrics decrement it, and the
runtime raises `BUDGET_EXHAUSTED` when the counter reaches zero.
