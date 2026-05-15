# Authentication

ARCP sessions are authenticated at handshake time by exchanging a
session-scoped credential. The PHP SDK ships three built-in
authentication schemes; consumers can register their own by
implementing `Arcp\Auth\AuthScheme`.

## Built-in schemes

| Scheme       | Class                       | When to use                                              |
| ------------ | --------------------------- | -------------------------------------------------------- |
| **None**     | `Arcp\Auth\NoneAuth`        | Loopback/in-process transports and local development.    |
| **Bearer**   | `Arcp\Auth\BearerAuth`      | Static API token shared between client and runtime.      |
| **JWT**      | `Arcp\Auth\JwtAuth`         | Asymmetric or HMAC-signed JWTs issued by an auth server. |

Each scheme produces an `Arcp\Auth\AuthResult` carrying the
authenticated principal (typed identifier) and the scheme name. The
runtime stores the result on the `Session` so downstream tool handlers
can read `Session::$principal` via `JobContext::$session`.

## Wiring an auth router

The runtime accepts an `AuthRouter` that fans out across the schemes
the deployment supports:

```php
use Arcp\Auth\AuthRouter;
use Arcp\Auth\BearerAuth;
use Arcp\Auth\JwtAuth;
use Arcp\Runtime\ARCPRuntime;

$runtime = new ARCPRuntime(
    authRouter: new AuthRouter([
        new BearerAuth(['key-a', 'key-b']),
        new JwtAuth(secretKey: \getenv('ARCP_JWT_HS256_SECRET')),
    ]),
);
```

The router tries each scheme in order and returns the first that
recognises the credential. If none match, the runtime emits
`session.unauthenticated` and tears the session down.

## Client side

Clients select the scheme by constructing the appropriate `Auth`
value object and passing it to `$client->open()`:

```php
use Arcp\Client\ARCPClient;
use Arcp\Messages\Session\Auth;

$client = new ARCPClient($transport);
$client->open(
    auth: Auth::bearer('key-a'),
    peer: new PeerInfo('cli', '0.1.0'),
    capabilities: new Capabilities(anonymous: false),
);
```

For unauthenticated loopback flows, pass `Auth::none()` and set the
`anonymous: true` capability so the runtime knows to accept the open.

## Custom schemes

Implement `Arcp\Auth\AuthScheme`:

```php
final class AwsSigV4Auth implements AuthScheme
{
    public function name(): string
    {
        return 'aws-sigv4';
    }

    public function verify(Auth $auth): ?AuthResult
    {
        // Inspect $auth->scheme / $auth->credential, return AuthResult
        // on success or null on "not for me, try the next scheme."
    }
}
```

Register the scheme by prepending it to the `AuthRouter`'s constructor
list. **Never** throw from `verify()` — return `null` and let the
router move on.

## Failure semantics

- Returning `null` from every scheme produces
  `Arcp\Messages\Session\SessionUnauthenticated`. The client receives
  it and `Arcp\Errors\UnauthenticatedException` is raised.
- Throwing from a scheme propagates as `Arcp\Errors\InternalException`
  — keep authentication side-effect free.

See `samples/permission_challenge/` for an end-to-end example that
combines authentication with permission leases.
