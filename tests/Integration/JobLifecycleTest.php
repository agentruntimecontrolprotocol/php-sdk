<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use function Amp\async;

use Amp\Cancellation;

use function Amp\delay;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Errors\AgentVersionNotAvailableException;
use Arcp\Errors\BudgetExhaustedException;
use Arcp\Errors\DeadlineExceededException;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\NotFoundException;
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
    public function testToolInvokeReturnsToolResult(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('echo', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->reportProgress(50, 'halfway');
                return ['echoed' => $arguments];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        $result = $client->invokeTool('echo', ['foo' => 'bar']);
        self::assertSame(['echoed' => ['foo' => 'bar']], $result->value);

        $client->close();
        $serverFuture->await();
    }

    public function testToolErrorPropagatesAsException(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('boom', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                throw new InvalidArgumentException('bad input');
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        $this->expectException(InvalidArgumentException::class);
        try {
            $client->invokeTool('boom');
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testUnknownToolReturnsNotFound(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        try {
            $client->invokeTool('nope');
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('nope', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testIdempotentReplayDoesNotReExecute(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
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
        $client->open(Auth::none(), new PeerInfo('cli', '0.1', principal: 'alice'), new Capabilities(anonymous: true));

        $key = new IdempotencyKey('refund-1');
        $first = $client->invokeTool('once', [], idempotencyKey: $key);
        self::assertSame(['ran' => 1], $first->value);

        // Second call with the same idempotency key: runtime returns ack
        // (not a new execution). The client's invoke contract waits for
        // the next response, which on replay is an ack — we don't assert
        // a value, only that no second execution happened.
        try {
            $client->invokeTool('once', [], deadlineSeconds: 0.5, idempotencyKey: $key);
        } catch (DeadlineExceededException) {
            // Expected: ack is not a ToolResult, so awaitResponse times out.
        } catch (InvalidArgumentException) {
            // Same expected outcome.
        }
        self::assertSame(1, $count, 'idempotency cache must prevent re-execution');

        $client->close();
        $serverFuture->await();
    }

    public function testMultipleConcurrentJobsCompleteIndependently(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
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
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        $r1 = $client->invokeTool('add', ['a' => 2, 'b' => 3]);
        $r2 = $client->invokeTool('add', ['a' => 10, 'b' => 100]);
        self::assertSame(['sum' => 5], $r1->value);
        self::assertSame(['sum' => 110], $r2->value);

        $client->close();
        $serverFuture->await();
    }

    public function testVersionedToolResolution(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
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
        $accepted = $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        self::assertNotEmpty($accepted->capabilities->agents);
        self::assertSame(['version' => '1.0.0'], $client->invokeTool('planner@1.0.0')->value);
        self::assertSame(['version' => '2.0.0'], $client->invokeTool('planner')->value);

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
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
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
        $client->open(Auth::none(), new PeerInfo('cli', '0.1', principal: 'alice'), new Capabilities(anonymous: true));

        $future = async(fn () => $client->invokeTool('slow'));
        $deadline = microtime(true) + 2.0;
        while ($runtime->jobs->count() === 0 && microtime(true) < $deadline) {
            delay(0.01);
        }
        $page = $client->listJobs(['agent' => 'slow'], limit: 1);
        self::assertCount(1, $page->jobs);
        self::assertSame('slow', $page->jobs[0]['agent'] ?? null);
        self::assertSame('running', $page->jobs[0]['status'] ?? null);

        $future->await();
        $client->close();
        $serverFuture->await();
    }

    public function testResultChunksAreAssembledByClient(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('chunker', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->emitResultChunk('res_x', 'hello, ');
                $ctx->emitResultChunk('res_x', 'world', more: false);
                return ['result_id' => 'res_x'];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        $result = $client->invokeTool('chunker');
        self::assertSame(['result_id' => 'res_x'], $result->value);
        self::assertTrue($client->resultChunks->isComplete('res_x'));
        self::assertSame('hello, world', $client->resultChunks->assemble('res_x'));

        $client->close();
        $serverFuture->await();
    }

    public function testCostBudgetExhaustionFailsJob(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('spender', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->emitMetric('cost.search', 0.60, 'USD');
                $ctx->emitMetric('cost.search', 0.40, 'USD');
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

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
