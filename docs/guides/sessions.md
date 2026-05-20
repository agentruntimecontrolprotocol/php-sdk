# Sessions (§6)

Sessions begin with `session.open`, complete with `session.accepted`,
and end with `session.close` or transport closure.

## Handshake

`ARCPClient::open()` sends `SessionOpen`; `HandshakeNegotiator` validates
capabilities and authentication, then emits `SessionAccepted`.

## Client side

```php
$accepted = $client->open(
    Auth::none(),
    new PeerInfo('cli', '0.1', principal: 'alice'),
    new Capabilities(anonymous: true, subscriptions: true),
);
```

## Runtime side

```php
$runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
$runtime->serve($transport);
```

## Session state machine

`SessionState` tracks opening, authenticated, rejected, and closed
states.

## Closing cleanly

Call `ARCPClient::close()`; the runtime also closes the transport in
`ARCPRuntime::serve()` cleanup.

## Capability negotiation

`Capabilities` is a typed DTO. Unsupported required capabilities reject
the session with `UNIMPLEMENTED`.

## Per-session DoS caps

Apply host-level caps at the transport and web-server layer: max frame
size, connection count, session TTL, and auth rate limits.

## Heartbeat (v1.1, §6.4)

Use `ARCPClient::ping()` for session liveness. Jobs can call
`JobContext::heartbeat()`.

## Back-pressure ack (v1.1, §6.5)

`Ack` is registered in the message catalog and used for replay and flow
control acknowledgement.

## Resume

Resume replay is backed by `EventLog::replayAfter()`. See
[Resume](./resume.md).
