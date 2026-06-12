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

    public function testProgressBodyMatchesSpecShape(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair();
        $runtime->registerTool('progress_tool', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->reportProgress(47, total: 120, units: 'files', message: 'refactoring');
                return ['ok' => true];
            }
        });

        $client->invokeTool('progress_tool');
        // §8.2.1: progress rides as job.event kind "progress" with body
        // {current, total?, units?, message?}.
        $found = null;
        foreach ($runtime->eventLog->replaySince($this->requireSessionId($client), 0) as $env) {
            $payload = $env->payload;
            if ($payload instanceof \Arcp\Messages\Execution\JobEvent && $payload->eventKind === 'progress') {
                $found = $payload->body;
            }
        }
        self::assertSame(
            ['current' => 47, 'total' => 120, 'units' => 'files', 'message' => 'refactoring'],
            $found,
        );

        $client->close();
        $serverFuture->await();
    }

    public function testProgressRejectsNegativeCurrentAndOverflow(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair();
        $runtime->registerTool('bad_progress', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $mode = $arguments['mode'] ?? 'negative';
                if ($mode === 'negative') {
                    $ctx->reportProgress(-1);
                } else {
                    $ctx->reportProgress(5, total: 3);
                }
                return null;
            }
        });

        $rejected = 0;
        foreach (['negative', 'overflow'] as $mode) {
            try {
                $client->invokeTool('bad_progress', ['mode' => $mode]);
                self::fail('expected InvalidRequestException for ' . $mode);
            } catch (\Arcp\Errors\InvalidRequestException) {
                // §8.2.1: current MUST be >= 0 and SHOULD be <= total.
                ++$rejected;
            }
        }
        self::assertSame(2, $rejected);

        $client->close();
        $serverFuture->await();
    }

    private function requireSessionId(ARCPClient $client): \Arcp\Ids\SessionId
    {
        $sid = $client->session->sessionId;
        self::assertNotNull($sid);
        return $sid;
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
