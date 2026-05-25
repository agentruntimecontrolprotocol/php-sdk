# Getting started

## Prerequisites

- PHP 8.4 or newer.
- Composer 2.x.
- Extensions: `pdo`, `pdo_sqlite`, `mbstring`, `json`.

## Install

From this repository:

```sh
composer install
```

As a dependency:

```sh
composer require arcp/sdk
```

## In-process demo (no network)

```php
use Amp\Cancellation;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;

$runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
$runtime->registerTool('echo', new class implements ToolHandler {
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        return ['echoed' => $arguments];
    }
});

[$serverTransport, $clientTransport] = MemoryTransport::pair();
$server = $runtime->serveAsync($serverTransport);

$client = new ARCPClient($clientTransport);
$client->open(Auth::none(), new PeerInfo('demo', '0.1'), new Capabilities(anonymous: true));

$result = $client->invokeTool('echo', ['hello' => 'php']);
print_r($result->value);

$client->close();
$server->await();
```

## Run over WebSocket

Start a runtime:

```sh
bin/arcp serve --host 127.0.0.1 --port 8765
```

Invoke a tool:

```sh
bin/arcp send ws://127.0.0.1:8765 echo -a '{"hello":"php"}'
```

## Run over stdio

`StdioTransport` frames envelopes as newline-delimited JSON. It is best
for subprocess agents where the parent process owns the client and the
child owns the runtime.

## What's next

- [Sessions](./guides/sessions.md)
- [Jobs](./guides/jobs.md)
- [Transports](./transports.md)
- [Recipes](./recipes.md)

## Runnable examples

Sample programs live under [`../samples/`](../samples/). They are kept
small and protocol-focused.
