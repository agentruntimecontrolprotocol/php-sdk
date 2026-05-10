<?php

declare(strict_types=1);

/*
 * 02 — Tool invocation with progress.
 *
 * Registers a `ingest` tool that streams progress as it processes records,
 * then invokes it from the client side. Demonstrates RFC §10 (jobs) and
 * RFC §10.1 (progress reporting).
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Cancellation;

use function Amp\delay;

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
$runtime->registerTool('ingest', new class () implements ToolHandler {
    #[\Override]
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        $records = $arguments['records'] ?? 10;
        if (!is_int($records)) {
            $records = 10;
        }
        for ($i = 1; $i <= $records; ++$i) {
            $cancellation?->throwIfRequested();
            $ctx->reportProgress((int) (100 * $i / $records), sprintf('Processed %d/%d', $i, $records));
            delay(0.01);
        }
        return ['ingested' => $records];
    }
});

[$serverT, $clientT] = MemoryTransport::pair();
$serverFuture = $runtime->serveAsync($serverT);

$client = new ARCPClient($clientT);
$client->open(Auth::none(), new PeerInfo('arcp-sample', '0.1'), new Capabilities(streaming: true, anonymous: true));

$result = $client->invokeTool('ingest', ['records' => 5]);
printf("Ingest result: %s\n", json_encode($result->value, \JSON_THROW_ON_ERROR));

$client->close();
$serverFuture->await();
