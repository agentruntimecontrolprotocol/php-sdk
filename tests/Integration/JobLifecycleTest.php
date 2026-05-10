<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Amp\Cancellation;
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
                throw new \Arcp\Errors\InvalidArgumentException('bad input');
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        $this->expectException(\Arcp\Errors\InvalidArgumentException::class);
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
        } catch (\Arcp\Errors\NotFoundException $e) {
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

        $key = new \Arcp\Ids\IdempotencyKey('refund-1');
        $first = $client->invokeTool('once', [], idempotencyKey: $key);
        self::assertSame(['ran' => 1], $first->value);

        // Second call with the same idempotency key: runtime returns ack
        // (not a new execution). The client's invoke contract waits for
        // the next response, which on replay is an ack — we don't assert
        // a value, only that no second execution happened.
        try {
            $client->invokeTool('once', [], idempotencyKey: $key, deadlineSeconds: 0.5);
        } catch (\Arcp\Errors\DeadlineExceededException) {
            // Expected: ack is not a ToolResult, so awaitResponse times out.
        } catch (\Arcp\Errors\InvalidArgumentException) {
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
}
