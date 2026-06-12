# result-chunk

Demonstrates ARCP v1.1 §8.4 result streaming. A tool emits `job.event`
payloads of kind `result_chunk`; the runtime mints the stable
`result_id` on the first chunk, the client buffers chunks in
`ARCPClient::$resultChunks`, and the terminating `job.result` carries
`final_status` + `result_id` (no inline result). Assembly happens once
the final chunk has `more=false`.
