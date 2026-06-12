<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Amp\Cancellation;
use Amp\Future;
use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Client\ARCPClient;
use Arcp\Messages\Artifacts\ArtifactRef;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Streaming\StreamKind;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class JobContextTest extends TestCase
{
    /** @return array{0: ARCPRuntime, 1: ARCPClient, 2: Future<mixed>} */
    private function pair(): array
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());
        return [$runtime, $client, $serverFuture];
    }

    public function testEmitMetricFromTool(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair();
        $runtime->registerTool('metric_tool', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->emitMetric('latency_ms', 42, 'ms', ['phase' => 'first']);
                $ctx->emitMetric('temperature', 3.14, 'celsius');
                return ['ok' => true];
            }
        });

        $result = $client->invokeTool('metric_tool');
        self::assertSame(['ok' => true], $result->result);

        $client->close();
        $serverFuture->await();
    }

    public function testOpenStreamEmitsOpenChunksAndClose(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair();
        $sawSid = null;
        $runtime->registerTool('stream_tool', new class ($sawSid) implements ToolHandler {
            public function __construct(public ?string &$sawSid)
            {
            }

            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                [$sid, $chunk, $close] = $ctx->openStream(StreamKind::Text, 'text/plain');
                $this->sawSid = (string) $sid;
                $chunk('hello world');
                $chunk(['kv' => 'value'], 'application/json');
                $chunk(null);
                $close();
                return ['streamed' => true];
            }
        });

        $result = $client->invokeTool('stream_tool');
        self::assertSame(['streamed' => true], $result->result);
        self::assertNotNull($sawSid);
        self::assertNotEmpty($sawSid);

        $client->close();
        $serverFuture->await();
    }

    public function testOpenStreamWithExplicitTotalChunks(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair();
        $runtime->registerTool('stream_tool2', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                [, $chunk, $close] = $ctx->openStream(StreamKind::Log);
                $chunk('one');
                $close(7);
                return ['ok' => true];
            }
        });

        $result = $client->invokeTool('stream_tool2');
        self::assertSame(['ok' => true], $result->result);

        $client->close();
        $serverFuture->await();
    }

    public function testPutArtifactReturnsArtifactRef(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair();
        $observedRef = null;
        $runtime->registerTool('artifact_tool', new class ($observedRef) implements ToolHandler {
            public function __construct(public ?ArtifactRef &$ref)
            {
            }

            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $this->ref = $ctx->putArtifact('text/plain', 'hello', 60);
                return ['id' => (string) $this->ref->artifactId];
            }
        });

        $result = $client->invokeTool('artifact_tool');
        self::assertIsArray($result->result);
        self::assertArrayHasKey('id', $result->result);
        self::assertInstanceOf(ArtifactRef::class, $observedRef);

        $client->close();
        $serverFuture->await();
    }

    public function testHeartbeatEmitsFromInsideToolContext(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair();
        $runtime->registerTool('heart_tool', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->heartbeat(60000, 'running');
                $ctx->heartbeat(); // defaults
                return null;
            }
        });

        $result = $client->invokeTool('heart_tool');
        self::assertNull($result->result);

        $client->close();
        $serverFuture->await();
    }
}
