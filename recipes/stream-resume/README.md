# stream-resume

A writer streams a large result as `job.event` chunks of kind
`result_chunk` (§8.4). The client records the highest processed
`event_seq`, reconnects with `session.hello {resume_token,
last_event_seq}` (§6.3), and reassembles the result from replayed plus
live chunks in `chunk_seq` order. The terminating `job.result` carries
`final_status` and the stream's `result_id`.
