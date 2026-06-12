<?php

declare(strict_types=1);

/*
 * progress — §8.2.1 progress events. Progress rides as a job.event of
 * kind "progress" with body {current, total?, units?, message?}.
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
$runtime->registerTool('refactor', new class () implements ToolHandler {
    #[\Override]
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        $files = ['queued', 'running', 'complete'];
        foreach ($files as $i => $phase) {
            // §8.2.1: current MUST be >= 0 and SHOULD NOT exceed total.
            $ctx->reportProgress($i + 1, total: count($files), units: 'steps', message: $phase);
        }
        return ['done' => true];
    }
});

[$serverT, $clientT] = MemoryTransport::pair();
$runtime->serveAsync($serverT);
$client = new ARCPClient($clientT);
$client->open(Auth::anonymous(), new PeerInfo('progress-demo', '0.1'), new Capabilities(features: ['progress']));
$client->invokeTool('refactor');

// Render the buffered progress events from the runtime's event log.
$sid = $client->session->sessionId;
if ($sid !== null) {
    foreach ($runtime->eventLog->replaySince($sid, 0) as $env) {
        $payload = $env->payload;
        if ($payload instanceof \Arcp\Messages\Execution\JobEvent && $payload->eventKind === 'progress') {
            $body = $payload->body;
            $current = $body['current'] ?? 0;
            $total = $body['total'] ?? 0;
            $units = $body['units'] ?? '';
            $message = $body['message'] ?? '';
            printf(
                "%d/%d %s %s\n",
                is_int($current) ? $current : 0,
                is_int($total) ? $total : 0,
                is_string($units) ? $units : '',
                is_string($message) ? $message : '',
            );
        }
    }
}
$client->close();
