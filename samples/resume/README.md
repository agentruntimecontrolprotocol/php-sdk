# resume

Token-based session resume (§6.3). A long-running job survives a
transport drop: the session parks for `resume_window_sec`, sequenced
events buffer, and the client reattaches with the `resume_token` from
its last welcome plus `last_event_seq`.

## Before ARCP

Reconnect logic is bespoke: clients re-poll, re-subscribe, and hope
the server kept enough history. Missed events are silently lost or
re-fetched with one-off cursors.

## With ARCP

```php
// the welcome carries the resume parameters
$welcome = $client->open($auth, $info, $caps);
$token = $welcome->resumeToken;          // rotates on every welcome
$window = $welcome->resumeWindowSec;     // how long the buffer lives

// after a drop: reconnect on a fresh transport
$client2->open($auth, $info, $caps,
    resumeToken: $token,
    lastEventSeq: $client->session->lastReceivedEventSeq ?? 0,
);
// buffered events with event_seq > last_event_seq replay, then live
```

An unknown, expired, or rotated-away token — or a `last_event_seq`
the buffer no longer covers — fails with `RESUME_WINDOW_EXPIRED`
(§12). Acks (`session.ack`, §6.5) release buffered events early.

## Try it

```bash
php samples/resume/main.php
```

## ARCP primitives

- Resume — §6.3: `session.hello {resume_token, last_event_seq}`.
- Token rotation — §6.3: every welcome mints a fresh token.
- Event acknowledgement — §6.5: acks free the replay buffer.
- `RESUME_WINDOW_EXPIRED` — §12.

## Variations

- Send `session.ack` periodically and watch the replay window shrink.
- Let the resume window lapse and observe the parked session's jobs
  being cancelled.
