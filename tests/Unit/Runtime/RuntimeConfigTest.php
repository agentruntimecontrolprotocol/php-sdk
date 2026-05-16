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
}
