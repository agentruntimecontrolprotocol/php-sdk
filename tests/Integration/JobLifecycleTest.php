<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use function Amp\async;

use Amp\Cancellation;

use function Amp\delay;

use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Client\ARCPClient;
use Arcp\Errors\AgentNotAvailableException;
use Arcp\Errors\AgentVersionNotAvailableException;
use Arcp\Errors\BudgetExhaustedException;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\IdempotencyKey;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class JobLifecycleTest extends TestCase
{
    public function testSubmitReturnsJobResult(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('echo', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->reportProgress(50, total: 100, units: 'percent', message: 'halfway');
                return ['echoed' => $arguments];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $result = $client->invokeTool('echo', ['foo' => 'bar']);
        self::assertSame(['echoed' => ['foo' => 'bar']], $result->result);

        $client->close();
        $serverFuture->await();
    }

    public function testJobErrorPropagatesAsException(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('boom', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                throw new InvalidRequestException('bad input');
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $this->expectException(InvalidRequestException::class);
        try {
            $client->invokeTool('boom');
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testUnknownAgentReturnsAgentNotAvailable(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        try {
            $client->invokeTool('nope');
            self::fail('expected AgentNotAvailableException');
        } catch (AgentNotAvailableException $e) {
            self::assertStringContainsString('nope', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testIdempotentReplayDoesNotReExecute(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $count = 0;
        $runtime->registerTool('once', new class ($count) implements ToolHandler {
            public function __construct(public int &$count)
            {
            }

            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $this->count += 1;
                return ['ran' => $this->count];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1', principal: 'alice'), new Capabilities());

        $key = new IdempotencyKey('refund-1');
        $first = $client->invokeTool('once', [], idempotencyKey: $key);
        self::assertSame(['ran' => 1], $first->result);

        // Second call with the same idempotency key: runtime replays the
        // original terminal job.result so the client sees the same value.
        $second = $client->invokeTool('once', [], idempotencyKey: $key);
        self::assertSame(['ran' => 1], $second->result);
        self::assertSame(1, $count, 'idempotency cache must prevent re-execution');

        $client->close();
        $serverFuture->await();
    }

    public function testIdempotentRetryReplaysOriginalAcceptance(): void
    {
        // §7.2: an identical retry receives the SAME job.accepted payload
        // (same job_id, budget captured at acceptance) as the original.
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('echo', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $accepted = [];
        $key = new IdempotencyKey('accept-replay');
        $client->invokeTool('echo', ['n' => 1], idempotencyKey: $key);
        $client->invokeTool('echo', ['n' => 1], idempotencyKey: $key);
        $sid = $client->session->sessionId;
        self::assertNotNull($sid);
        foreach ($runtime->eventLog->replayAfter('') as $env) {
            if ($env->payload instanceof \Arcp\Messages\Execution\JobAccepted) {
                $accepted[] = (string) $env->payload->jobId;
            }
        }
        self::assertCount(1, array_unique($accepted), 'replays must reference the original job_id');

        $client->close();
        $serverFuture->await();
    }

    public function testIdempotencyKeyReuseWithConflictingParamsIsDuplicateKey(): void
    {
        // §7.2: a reused key with conflicting parameters returns
        // DUPLICATE_KEY (canonical fingerprint over the full submit).
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('echo', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                return $arguments;
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $key = new IdempotencyKey('conflict-1');
        $client->invokeTool('echo', ['b' => 2, 'a' => 1], idempotencyKey: $key);
        // Key-order-only difference is the SAME fingerprint: replayed.
        $sameParams = $client->invokeTool('echo', ['a' => 1, 'b' => 2], idempotencyKey: $key);
        self::assertSame(['b' => 2, 'a' => 1], $sameParams->result);

        try {
            $client->invokeTool('echo', ['a' => 999], idempotencyKey: $key);
            self::fail('expected DuplicateKeyException');
        } catch (\Arcp\Errors\DuplicateKeyException $e) {
            self::assertStringContainsString('conflict-1', $e->getMessage());
        }

        $client->close();
        $serverFuture->await();
    }

    public function testMultipleConcurrentJobsCompleteIndependently(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('add', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $a = $arguments['a'] ?? 0;
                $b = $arguments['b'] ?? 0;
                return ['sum' => (\is_int($a) ? $a : 0) + (\is_int($b) ? $b : 0)];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $r1 = $client->invokeTool('add', ['a' => 2, 'b' => 3]);
        $r2 = $client->invokeTool('add', ['a' => 10, 'b' => 100]);
        self::assertSame(['sum' => 5], $r1->result);
        self::assertSame(['sum' => 110], $r2->result);

        $client->close();
        $serverFuture->await();
    }

    public function testVersionedToolResolution(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        foreach (['1.0.0', '2.0.0'] as $version) {
            $runtime->registerToolVersion('planner', $version, new class ($version) implements ToolHandler {
                public function __construct(private readonly string $version)
                {
                }

                #[\Override]
                public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
                {
                    return ['version' => $this->version];
                }
            });
        }
        $runtime->setDefaultToolVersion('planner', '2.0.0');
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $accepted = $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        self::assertNotEmpty($accepted->capabilities->agents);
        self::assertSame(['version' => '1.0.0'], $client->invokeTool('planner@1.0.0')->result);
        self::assertSame(['version' => '2.0.0'], $client->invokeTool('planner')->result);

        $caught = null;
        try {
            $client->invokeTool('planner@9.9.9');
            self::fail('expected AgentVersionNotAvailableException');
        } catch (AgentVersionNotAvailableException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertInstanceOf(AgentVersionNotAvailableException::class, $caught);
    }

    public function testListJobsReturnsRunningJobsWithPagination(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('slow', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                delay(0.2, cancellation: $cancellation);
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1', principal: 'alice'), new Capabilities(features: ['list_jobs']));

        $future = async(fn () => $client->invokeTool('slow'));
        $deadline = microtime(true) + 2.0;
        while ($runtime->jobs->count() === 0 && microtime(true) < $deadline) {
            delay(0.01);
        }
        $page = $client->listJobs(['agent' => 'slow'], limit: 1);
        self::assertCount(1, $page->jobs);
        self::assertSame('slow', $page->jobs[0]['agent'] ?? null);
        self::assertSame('running', $page->jobs[0]['status'] ?? null);
        // §6.6: entries carry the full spec shape.
        $entry = $page->jobs[0] ?? [];
        foreach (['job_id', 'lease', 'parent_job_id', 'created_at', 'trace_id', 'last_event_seq'] as $key) {
            self::assertArrayHasKey($key, $entry);
        }
        // The status job.event emitted at acceptance is sequenced, so a
        // running job already has a last_event_seq watermark.
        self::assertIsInt($entry['last_event_seq'] ?? null);

        $future->await();
        $client->close();
        $serverFuture->await();
    }

    public function testListJobsReturnsTerminalJobsWithinRetentionWindow(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('fast', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1', principal: 'alice'), new Capabilities(features: ['list_jobs']));

        // Run two short jobs that complete immediately. With the pre-fix
        // behavior, these would vanish from list_jobs the moment they
        // reached the `success` terminal (§7.3).
        $client->invokeTool('fast');
        $client->invokeTool('fast');

        $page = $client->listJobs(['status' => ['success']]);
        self::assertGreaterThanOrEqual(2, \count($page->jobs));
        foreach ($page->jobs as $entry) {
            self::assertSame('success', $entry['status'] ?? null);
            self::assertSame('fast', $entry['agent'] ?? null);
        }

        $client->close();
        $serverFuture->await();
    }

    public function testResultChunksAreAssembledByClient(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('chunker', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->emitResultChunk('hello, ');
                $ctx->emitResultChunk('world', more: false);
                return null;
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        // §8.4: the terminating job.result carries final_status +
        // result_id (+ result_size); the result itself was streamed.
        $result = $client->invokeTool('chunker');
        self::assertNull($result->result);
        $resultId = $result->resultId;
        self::assertNotNull($resultId);
        self::assertSame(\strlen('hello, world'), $result->resultSize);
        self::assertTrue($client->resultChunks->isComplete($resultId));
        self::assertSame('hello, world', $client->resultChunks->assemble($resultId));

        $client->close();
        $serverFuture->await();
    }

    public function testMixingInlineResultAndChunksIsRejected(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('mixer', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->emitResultChunk('hello', more: false);
                return ['also' => 'inline']; // §8.4 violation
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        try {
            $client->invokeTool('mixer');
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertStringContainsString('§8.4', $e->getMessage());
        }

        $client->close();
        $serverFuture->await();
    }

    public function testDivergentChunkRetransmissionIsRejected(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('diverge', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->emitResultChunk('hello', seq: 0);
                $ctx->emitResultChunk('hello', seq: 0); // byte-identical: tolerated
                $ctx->emitResultChunk('HELLO', seq: 0); // divergent: rejected
                return null;
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        try {
            $client->invokeTool('diverge');
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertStringContainsString('diverges', $e->getMessage());
        }

        $client->close();
        $serverFuture->await();
    }

    public function testCostBudgetExhaustionFailsJob(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('spender', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->emitMetric('cost.search', 0.60, 'USD');
                $ctx->emitMetric('cost.search', 0.50, 'USD');
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $caught = null;
        try {
            $client->invokeTool('spender', ['lease' => ['cost.budget' => ['USD:1.00']]]);
            self::fail('expected BudgetExhaustedException');
        } catch (BudgetExhaustedException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertInstanceOf(BudgetExhaustedException::class, $caught);
    }
}
