# multi-agent-budget

A planner divides a total cost budget into worker leases, emits
`cost.delegate` metrics for each grant, and skips work that no longer
fits the remaining cap.
