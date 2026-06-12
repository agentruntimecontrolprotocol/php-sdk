<?php

declare(strict_types=1);

/*
 * result-chunk — §8.4 result streaming. Chunks ride as job.event kind
 * "result_chunk"; the terminating job.result carries final_status +
 * result_id (never an inline result once chunks were emitted).
 */

use Amp\Cancellation;
use Arcp\Client\ARCPClient;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;

require __DIR__ . '/../../vendor/autoload.php';

$runtime = new ARCPRuntime();
$runtime->registerTool('reporter', new class () implements ToolHandler {
    #[\Override]
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        // The runtime mints the stable result_id on the first chunk.
        $ctx->emitResultChunk('alpha, ');
        $ctx->emitResultChunk('beta', more: false);
        // §8.4: a streamed job must NOT also return an inline result.
        return null;
    }
});

[$serverT, $clientT] = MemoryTransport::pair();
$runtime->serveAsync($serverT);
$client = new ARCPClient($clientT);
$client->open(Auth::anonymous(), new PeerInfo('result-chunk-demo', '0.1'), new Capabilities(features: ['result_chunk']));

$result = $client->invokeTool('reporter');
// The terminal job.result references the streamed result.
$resultId = $result->resultId;
if (is_string($resultId) && $client->resultChunks->isComplete($resultId)) {
    printf("result_id=%s size=%d bytes\n", $resultId, (int) $result->resultSize);
    printf("%s\n", $client->resultChunks->assemble($resultId));
}
$client->close();
