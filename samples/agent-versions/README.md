# agent-versions

Demonstrates ARCP v1.1 agent references. The runtime registers multiple
versions of one logical agent; clients can invoke either the bare default
or a pinned `name@version`. A missing pinned version raises
`AgentVersionNotAvailableException`.
