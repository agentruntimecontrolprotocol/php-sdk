-- ARCP event log schema (RFC §6.4 dedup, §19 resume).
-- The event log is the dedupe authority and the replay source. We keep it
-- minimal here: one row per envelope, indexed for the queries that
-- subscriptions and resume actually issue.

CREATE TABLE IF NOT EXISTS events (
    rowid             INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id        TEXT NOT NULL UNIQUE,
    session_id        TEXT,
    job_id            TEXT,
    stream_id         TEXT,
    trace_id          TEXT,
    type              TEXT NOT NULL,
    priority          TEXT NOT NULL DEFAULT 'normal',
    correlation_id    TEXT,
    idempotency_key   TEXT,
    timestamp         TEXT NOT NULL,
    payload_json      TEXT NOT NULL,
    -- 1 = runtime→client (outbound), 0 = client→runtime (inbound).
    -- Resume/backfill replay only outbound rows (RFC §6.3); inbound rows are
    -- retained for transport dedup and audit.
    outbound          INTEGER NOT NULL DEFAULT 1,
    -- Session-scoped §8.3 sequence stamped on sequenced job messages.
    -- NULL for session-control messages; §6.3 resume replays rows with
    -- event_seq > last_event_seq, and §6.5 acks release rows at or
    -- below the acknowledged watermark.
    event_seq         INTEGER
);

CREATE INDEX IF NOT EXISTS events_session_idx  ON events(session_id, rowid);
CREATE INDEX IF NOT EXISTS events_job_idx      ON events(job_id, rowid);
CREATE INDEX IF NOT EXISTS events_stream_idx   ON events(stream_id, rowid);
CREATE INDEX IF NOT EXISTS events_trace_idx    ON events(trace_id, rowid);
CREATE INDEX IF NOT EXISTS events_type_idx     ON events(type, rowid);
CREATE INDEX IF NOT EXISTS events_seq_idx      ON events(session_id, event_seq);

-- §7.2 idempotency: (principal, idempotency_key) → canonical request
-- fingerprint + the original job.accepted message id (claimed at
-- acceptance) + the terminal outcome message id ('' until terminal).
-- An identical retry replays the original acceptance; a fingerprint
-- mismatch returns DUPLICATE_KEY.
CREATE TABLE IF NOT EXISTS idempotency_cache (
    principal            TEXT NOT NULL,
    idempotency_key      TEXT NOT NULL,
    fingerprint          TEXT NOT NULL DEFAULT '',
    accepted_message_id  TEXT NOT NULL DEFAULT '',
    outcome_message_id   TEXT NOT NULL DEFAULT '',
    expires_at           TEXT NOT NULL,
    PRIMARY KEY (principal, idempotency_key)
);

CREATE INDEX IF NOT EXISTS idempotency_expiry_idx ON idempotency_cache(expires_at);
