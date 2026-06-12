<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\MessageCatalog;
use Arcp\Extensions\ExtensionRegistry;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\RuntimeConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RuntimeConfigTest extends TestCase
{
    public function testDefaultsAreAllNull(): void
    {
        $config = new RuntimeConfig();
        self::assertNull($config->registry);
        self::assertNull($config->eventLog);
        self::assertNull($config->clock);
        self::assertNull($config->logger);
        self::assertNull($config->capabilities);
        self::assertNull($config->authRouter);
        self::assertNull($config->extensions);
        self::assertNull($config->runtimeIdentity);
    }

    public function testWithConfigBuildsRuntime(): void
    {
        $config = new RuntimeConfig(
            registry: MessageCatalog::create(),
            clock: new SystemClock(),
            logger: new NullLogger(),
            capabilities: new Capabilities(),
            authRouter: new AuthRouter([new NoneAuth()]),
            extensions: new ExtensionRegistry(),
            runtimeIdentity: new PeerInfo('test-runtime', '0.0.1'),
        );

        $runtime = ARCPRuntime::withConfig($config);
        self::assertNotNull($runtime->runtimeIdentity);
        self::assertSame('test-runtime', $runtime->runtimeIdentity->kind);
    }

    public function testResolveToolThrowsForAmbiguousUnversionedName(): void
    {
        $handler = new class () implements \Arcp\Runtime\ToolHandler {
            #[\Override]
            public function invoke(array $arguments, \Arcp\Runtime\JobContext $ctx, ?\Amp\Cancellation $cancellation = null): mixed
            {
                return null;
            }
        };
        $runtime = new ARCPRuntime();
        $runtime->registerToolVersion('demo', '1.0.0', $handler);
        $runtime->registerToolVersion('demo', '2.0.0', $handler);

        // No default and no unversioned handler => ambiguous, must not pick
        // an arbitrary version.
        $this->expectException(\Arcp\Errors\AgentVersionNotAvailableException::class);
        $runtime->resolveTool(\Arcp\Runtime\AgentRef::parse('demo'));
    }

    public function testResolveToolReturnsSoleRegisteredVersion(): void
    {
        $handler = new class () implements \Arcp\Runtime\ToolHandler {
            #[\Override]
            public function invoke(array $arguments, \Arcp\Runtime\JobContext $ctx, ?\Amp\Cancellation $cancellation = null): mixed
            {
                return null;
            }
        };
        $runtime = new ARCPRuntime();
        $runtime->registerToolVersion('solo', '3.1.4', $handler);

        $resolved = $runtime->resolveTool(\Arcp\Runtime\AgentRef::parse('solo'));
        self::assertNotNull($resolved);
        self::assertSame('3.1.4', $resolved->version);
    }
}
