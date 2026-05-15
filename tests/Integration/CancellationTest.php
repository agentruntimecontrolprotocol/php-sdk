<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use function Amp\async;

use Amp\Cancellation;
use Amp\DeferredFuture;

use function Amp\delay;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Ids\JobId;
use Arcp\Messages\Execution\ToolResult;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class CancellationTest extends TestCase
{
    public function testCancellingJobInTerminatesCooperatively(): void
    {
        /** @var DeferredFuture<JobId> $started */
        $started = new DeferredFuture();
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('block', new readonly class ($started) implements ToolHandler {
            /** @param DeferredFuture<JobId> $started */
            public function __construct(private DeferredFuture $started)
            {
            }

            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $this->started->complete($ctx->jobId);
                for (;;) {
                    $cancellation?->throwIfRequested();
                    delay(0.01);
                }
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        // Issue the tool invocation in a background fiber so we can cancel.
        $invocation = async(fn (): ToolResult => $client->invokeTool('block'));

        /** @var JobId $jobId */
        $jobId = $started->getFuture()->await();
        self::assertNotNull($jobId);

        // Allow the runtime to register the job, then issue cancel.
        delay(0.01);
        $client->cancelJob($jobId);

        $caught = null;
        try {
            $invocation->await();
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'invoke should not return successfully after cancel');

        $client->close();
        $serverFuture->await();
    }
}
