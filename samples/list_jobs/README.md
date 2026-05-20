# list_jobs

Demonstrates ARCP v1.1 `session.list_jobs` / `session.jobs`: submit a
long-running job, list visible jobs by agent and status, then continue
with the returned cursor if another page exists.

Key APIs:

- `ARCPClient::listJobs()`
- `Jobs::$jobs`
- `Jobs::$nextCursor`
