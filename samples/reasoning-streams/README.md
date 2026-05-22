# reasoning-streams

A primary agent emits its reasoning as a `kind: thought` stream. A
mirror peer runtime subscribes, runs a smaller critic model on each
thought, and delegates `arcpx.mirror.critique.v1` events back into
the primary's session. Bounded by a token budget — when spent, the
mirror unsubscribes and the primary continues without critique.

## Before ARCP

Self-critique loops drift: there's no clean way to (a) expose just
the reasoning to a separate process for a second opinion, (b) cap
the rounds, (c) gracefully step back when the critic budget is
spent. Sidecar a "second LLM call" before each step and the
indirection burns tokens whether you need it or not.

## With ARCP

```php
// mirror peer subscribes to the primary's thought stream...
$mirror->subscribe(
    ['session_id' => [$targetSessionId], 'types' => ['stream.chunk']],
    function (Envelope $env) use ($mirror) {
        // ...and delegates critiques back as namespaced events.
        $mirror->session->transport->send(new Envelope(
            payload: new AgentDelegate(['context' => ['critique' => [...]]]),
            ...
        ));
    },
);
```

The mirror is a *peer runtime* (`agent_handoff: true`,
`subscriptions: true`, `trust_level: trusted`), not an Observer —
it both reads and writes back into the primary's session.

## ARCP primitives

- `kind: thought` reasoning streams — RFC §11.4.
- Subscriptions with type filter — §13.2.
- Custom extension event under `arcpx.<domain>.<name>.v<n>` — §21.1.
- `agent.delegate` for cross-runtime delivery — §14.
- `tokens.used` budget — §17.3.1.

## File tour

- `main.php` — boots primary + mirror, wires the critique queue.
- `agents.php` — `primaryStep` + `critiqueThought` LLM stubs.

## Variations

- Multiple mirrors (security / factuality / style) subscribed in
  parallel; primary merges critiques by severity.
- Persist critiques to the SQLite sink in
  [subscriptions](../subscribe) for drift analysis.
- Replace the critic LLM with a deterministic verifier (linter,
  type checker) that returns `severity` from a rule set.
