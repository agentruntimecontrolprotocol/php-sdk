<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Amp\Cancellation;
use Amp\DeferredFuture;

use function Amp\delay;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class SubscriptionTest extends TestCase
{
    public function testSubscribeReceivesBackfillCompleteMarker(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(subscriptions: true, anonymous: true));

        $sawBackfillMarker = new DeferredFuture();
        $client->subscribe(
            ['types' => ['event.emit']],
            function (Envelope $env) use ($sawBackfillMarker): void {
                $payload = $env->payload;
                if ($payload instanceof \Arcp\Messages\Telemetry\EventEmit && $payload->eventType === 'subscription.backfill_complete') {
                    if (!$sawBackfillMarker->isComplete()) {
                        $sawBackfillMarker->complete(true);
                    }
                }
            },
        );

        $marker = $sawBackfillMarker->getFuture()->await();
        self::assertTrue($marker, 'received backfill_complete marker');

        $client->close();
        $serverFuture->await();
    }

    public function testSubscriptionFiltersByType(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('emitProgress', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->reportProgress(50);
                $ctx->emitLog('info', 'midway');
                return null;
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(subscriptions: true, anonymous: true));

        $observed = [];
        $client->subscribe(
            ['types' => ['log']],
            function (Envelope $env) use (&$observed): void {
                $observed[] = $env->type();
            },
        );

        // Give the subscription a beat to settle.
        delay(0.01);
        $client->invokeTool('emitProgress');
        delay(0.05);

        // Should observe at least one log envelope, and no `job.progress`.
        self::assertContains('log', $observed, 'expected log envelope');
        self::assertNotContains('job.progress', $observed, 'progress should be filtered out');

        $client->close();
        $serverFuture->await();
    }

    public function testCannotSubscribeToOtherSession(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(subscriptions: true, anonymous: true));

        $caught = null;
        try {
            $client->subscribe(['session_id' => ['sess_someoneElse']], fn () => null);
        } catch (\Arcp\Errors\PermissionDeniedException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertInstanceOf(\Arcp\Errors\PermissionDeniedException::class, $caught);
    }
}
