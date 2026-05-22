# result-chunk

Demonstrates ARCP v1.1 `job.result_chunk`. A tool emits chunks with the
same `result_id`; the client automatically buffers chunks in
`ARCPClient::$resultChunks` and assembles the final result once the final
chunk has `more=false`.
