# Authentication (§6.1)

PHP auth is runtime-owned. The client sends an `Auth` block in
`session.open`; the runtime verifies it through `AuthRouter`.

## Client side

```php
$client->open(
    Auth::bearer($token),
    new PeerInfo('cli', '0.1'),
    new Capabilities(),
);
```

For local tests:

```php
$client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));
```

## Static tokens (development, tests)

`BearerAuth` accepts a known token and returns an authenticated
principal. `NoneAuth` accepts anonymous sessions.

```php
$runtime = new ARCPRuntime(
    authRouter: new AuthRouter([new BearerAuth('secret', principal: 'alice')]),
);
```

## Custom verifier

Implement `Arcp\Auth\AuthScheme`:

```php
final class HeaderAuth implements AuthScheme
{
    public function scheme(): string
    {
        return 'bearer';
    }

    public function verify(Auth $auth, PeerInfo $peer): AuthResult
    {
        return $auth->token === 'secret'
            ? AuthResult::accepted('alice')
            : AuthResult::rejected('bad token');
    }
}
```

## Where the principal lives

After handshake, the runtime stores the resolved principal on
`Session::$principal`. The client stores its own principal from
`PeerInfo`.

## Sessions, resume, and auth

Resume requests must be authenticated under the same principal that owns
the session. Persist resume metadata with your own session store if the
runtime crosses process boundaries.

## DNS-rebind protection

Handle DNS-rebind and origin checks at your HTTP/WebSocket host layer
before creating an ARCP transport.

## Vendor auth extensions

Use extension namespaces for deployment-specific auth metadata. Keep
core `Auth` fields compatible with the canonical schemes.

## Runnable example

See `samples/capability-negotiation/` and the handshake tests under
`tests/Integration/HandshakeTest.php`.
