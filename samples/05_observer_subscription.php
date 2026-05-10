<?php

declare(strict_types=1);

/*
 * 05 — Observer subscription.
 *
 * Active client invokes a tool while an observer client subscribes to
 * `log` events. Demonstrates RFC §13 (subscriptions, backfill marker).
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Cancellation;

use function Amp\delay;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Messages\Telemetry\LogEvent;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;

$runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
$runtime->registerTool('chatty', new class () implements ToolHandler {
    #[\Override]
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        $ctx->emitLog('info', 'Starting work');
        delay(0.01);
        $ctx->emitLog('warn', 'Heads up: rate limit close');
        delay(0.01);
        $ctx->emitLog('info', 'Done');
        return null;
    }
});

[$serverT, $activeT] = MemoryTransport::pair();
$serverFuture = $runtime->serveAsync($serverT);

$active = new ARCPClient($activeT);
$active->open(Auth::none(), new PeerInfo('arcp-active', '0.1'), new Capabilities(subscriptions: true, anonymous: true));

$active->subscribe(['types' => ['log', 'event.emit']], static function (Envelope $env): void {
    $msg = $env->payload;
    if ($msg instanceof LogEvent) {
        printf("[obs] %s: %s\n", $msg->level, $msg->message);
    } elseif ($msg instanceof EventEmit && $msg->eventType === 'subscription.backfill_complete') {
        echo "[obs] backfill complete; now live tail\n";
    }
});

$active->invokeTool('chatty');
delay(0.05); // give subscription events time to drain

$active->close();
$serverFuture->await();
