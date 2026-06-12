<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use function Amp\async;

use Amp\Cancellation;
use Amp\DeferredFuture;

use function Amp\delay;

use Amp\Future;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Ids\JobId;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class SubscriptionTest extends TestCase
{
    private function runtime(): ARCPRuntime
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('emit_progress', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                // Hold the job open long enough for a subscriber to attach.
                delay(0.1, cancellation: $cancellation);
                $ctx->reportProgress(50);
                $ctx->emitLog('info', 'midway');
                return null;
            }
        });
        return $runtime;
    }

    /** @return array{0: ARCPClient, 1: Future<mixed>} */
    private function client(ARCPRuntime $runtime, string $name = 'cli'): array
    {
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(
            Auth::none(),
            new PeerInfo($name, '0.1'),
            new Capabilities(subscriptions: true, anonymous: true, features: ['subscribe']),
        );
        return [$client, $serverFuture];
    }

    /** @return array{0: JobId, 1: Future<mixed>} */
    private function startJob(ARCPRuntime $runtime, ARCPClient $client): array
    {
        $future = async(fn () => $client->invokeTool('emit_progress'));
        $deadline = microtime(true) + 2.0;
        while ($runtime->jobs->count() === 0 && microtime(true) < $deadline) {
            delay(0.01);
        }
        $jobs = $runtime->jobs->all();
        self::assertNotSame([], $jobs);
        return [$jobs[0]->id, $future];
    }

    public function testSubscribeReceivesBackfillCompleteMarker(): void
    {
        $runtime = $this->runtime();
        [$client, $serverFuture] = $this->client($runtime);
        [$jobId, $jobFuture] = $this->startJob($runtime, $client);

        $sawBackfillMarker = new DeferredFuture();
        $client->subscribe(
            $jobId,
            function (Envelope $env) use ($sawBackfillMarker): void {
                $payload = $env->payload;
                if ($payload instanceof EventEmit && $payload->eventType === 'subscription.backfill_complete' && !$sawBackfillMarker->isComplete()) {
                    $sawBackfillMarker->complete(true);
                }
            },
        );

        $marker = $sawBackfillMarker->getFuture()->await();
        self::assertTrue($marker, 'received backfill_complete marker');

        $jobFuture->await();
        $client->close();
        $serverFuture->await();
    }

    public function testSubscriberObservesJobEventsLive(): void
    {
        $runtime = $this->runtime();
        [$client, $serverFuture] = $this->client($runtime);
        [$jobId, $jobFuture] = $this->startJob($runtime, $client);

        $observed = [];
        $seqs = [];
        $client->subscribe(
            $jobId,
            function (Envelope $env) use (&$observed, &$seqs): void {
                $observed[] = $env->type();
                if (\in_array($env->type(), ['job.event', 'job.progress', 'job.result'], true)) {
                    $seqs[] = $env->eventSeq;
                }
            },
        );

        $jobFuture->await();
        delay(0.05);

        self::assertContains('log', $observed, 'expected the job log envelope');
        self::assertContains('job.progress', $observed, 'expected the job progress envelope');
        self::assertContains('job.result', $observed, 'expected the terminal job.result');

        // §8.3: sequenced job messages carry the session-scoped,
        // monotonically increasing event_seq (#56, #132).
        self::assertNotEmpty($seqs);
        $previous = 0;
        foreach ($seqs as $seq) {
            self::assertNotNull($seq, 'job event missing event_seq');
            self::assertGreaterThan($previous, $seq);
            $previous = $seq;
        }

        $client->close();
        $serverFuture->await();
    }

    public function testSamePrincipalSecondSessionCanSubscribe(): void
    {
        // §7.6: a dashboard session under the same principal may attach to
        // a job submitted from a different session and observe it live.
        $runtime = $this->runtime();
        [$clientA, $futureA] = $this->client($runtime, 'cli-a');
        [$clientB, $futureB] = $this->client($runtime, 'cli-b');
        [$jobId, $jobFuture] = $this->startJob($runtime, $clientA);

        $observed = [];
        $subscribed = $clientB->subscribe(
            $jobId,
            function (Envelope $env) use (&$observed): void {
                $observed[] = $env->type();
            },
        );
        self::assertSame('running', $subscribed->currentStatus);
        self::assertSame('emit_progress', $subscribed->agent);

        $jobFuture->await();
        delay(0.05);
        self::assertContains('job.result', $observed, 'cross-session subscriber sees events');

        $clientA->close();
        $clientB->close();
        $futureA->await();
        $futureB->await();
    }

    public function testSubscriberDoesNotObserveOtherJobs(): void
    {
        $runtime = $this->runtime();
        [$client, $serverFuture] = $this->client($runtime);
        [$jobAId, $jobAFuture] = $this->startJob($runtime, $client);

        $observedJobs = [];
        $client->subscribe(
            $jobAId,
            function (Envelope $env) use (&$observedJobs): void {
                if ($env->jobId !== null) {
                    $observedJobs[(string) $env->jobId] = true;
                }
            },
        );
        $jobAFuture->await();

        // A second job in the same session must not reach the job-A
        // subscriber (§7.6: subscriptions are job-scoped).
        $jobBFuture = async(fn () => $client->invokeTool('emit_progress'));
        $jobBFuture->await();
        delay(0.05);

        $foreign = array_diff(array_keys($observedJobs), [(string) $jobAId]);
        self::assertEmpty($foreign, 'subscriber observed envelopes from other jobs');

        $client->close();
        $serverFuture->await();
    }
}
