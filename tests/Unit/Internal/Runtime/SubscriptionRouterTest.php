<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Internal\Runtime;

use function Amp\async;

use Amp\Cancellation;

use function Amp\delay;

use Amp\Future;
use Arcp\Client\ARCPClient;
use Arcp\Errors\JobNotFoundException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Ids\JobId;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class SubscriptionRouterTest extends TestCase
{
    /** @return array{0: ARCPRuntime, 1: ARCPClient, 2: Future<mixed>} */
    private function pair(ARCPRuntime $runtime): array
    {
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(
            Auth::none(),
            new PeerInfo('cli', '0.1'),
            new Capabilities(subscriptions: true, anonymous: true, features: ['subscribe']),
        );
        return [$runtime, $client, $serverFuture];
    }

    private function runtimeWithSlowTool(): ARCPRuntime
    {
        // No auth router: each anonymous session gets a server-assigned
        // opaque principal, so cross-principal checks are observable.
        $runtime = new ARCPRuntime();
        $runtime->registerTool('slow', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                delay(0.3, cancellation: $cancellation);
                return ['ok' => true];
            }
        });
        return $runtime;
    }

    /** @return array{0: JobId, 1: Future<mixed>} */
    private function startJob(ARCPRuntime $runtime, ARCPClient $client): array
    {
        $future = async(fn () => $client->invokeTool('slow'));
        $deadline = microtime(true) + 2.0;
        while ($runtime->jobs->count() === 0 && microtime(true) < $deadline) {
            delay(0.01);
        }
        $jobs = $runtime->jobs->all();
        self::assertNotSame([], $jobs, 'expected a registered job');
        return [$jobs[0]->id, $future];
    }

    public function testSubscribeToUnknownJobIsJobNotFound(): void
    {
        [, $client, $serverFuture] = $this->pair($this->runtimeWithSlowTool());
        $caught = null;
        try {
            $client->subscribe(new JobId('job_missing'), fn (): null => null);
        } catch (JobNotFoundException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertInstanceOf(JobNotFoundException::class, $caught);
    }

    public function testSubscribeToOwnJobReturnsSpecPayload(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair($this->runtimeWithSlowTool());
        [$jobId, $future] = $this->startJob($runtime, $client);

        $subscribed = $client->subscribe($jobId, fn (): null => null);
        self::assertSame((string) $jobId, (string) $subscribed->jobId);
        self::assertSame('running', $subscribed->currentStatus);
        self::assertSame('slow', $subscribed->agent);
        self::assertFalse($subscribed->replayed);

        $future->await();
        $client->close();
        $serverFuture->await();
    }

    public function testSubscribeFromOtherPrincipalIsPermissionDenied(): void
    {
        // Two anonymous sessions get distinct server-assigned principals,
        // so observing another session's job must be rejected (§7.6).
        $runtime = $this->runtimeWithSlowTool();
        [, $clientA, $futureA] = $this->pair($runtime);
        [, $clientB, $futureB] = $this->pair($runtime);
        [$jobId, $jobFuture] = $this->startJob($runtime, $clientA);

        $caught = null;
        try {
            $clientB->subscribe($jobId, fn (): null => null);
        } catch (PermissionDeniedException $e) {
            $caught = $e;
        } finally {
            $jobFuture->await();
            $clientA->close();
            $clientB->close();
            $futureA->await();
            $futureB->await();
        }
        self::assertInstanceOf(PermissionDeniedException::class, $caught);
    }

    public function testUnsubscribeIsSilentAndIdempotent(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair($this->runtimeWithSlowTool());
        [$jobId, $future] = $this->startJob($runtime, $client);

        $client->subscribe($jobId, fn (): null => null);
        $before = $runtime->subscriptions->count();
        self::assertSame(1, $before);

        $client->unsubscribe($jobId);
        $deadline = microtime(true) + 2.0;
        $remaining = $runtime->subscriptions->count();
        while ($remaining !== 0 && microtime(true) < $deadline) {
            delay(0.01);
            $remaining = $runtime->subscriptions->count();
        }
        self::assertSame(0, $remaining);

        // §7.6: a second unsubscribe is a silent no-op, not a nack.
        $client->unsubscribe($jobId);
        delay(0.05);
        self::assertSame(0, $runtime->subscriptions->count());

        $future->await();
        $client->close();
        $serverFuture->await();
    }
}
