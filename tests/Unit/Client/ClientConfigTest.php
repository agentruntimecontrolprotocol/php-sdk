<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Client;

use Arcp\Client\ARCPClient;
use Arcp\Client\ClientConfig;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\MessageCatalog;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientConfigTest extends TestCase
{
    public function testDefaultsExposeOnlyTransport(): void
    {
        [$a] = MemoryTransport::pair();
        $config = new ClientConfig(transport: $a);

        self::assertSame($a, $config->transport);
        self::assertNull($config->registry);
        self::assertNull($config->clock);
        self::assertNull($config->logger);
        self::assertNull($config->humanInputHandler);
        self::assertNull($config->permissionHandler);
    }

    public function testWithConfigBuildsAClient(): void
    {
        [$a] = MemoryTransport::pair();
        $config = new ClientConfig(
            transport: $a,
            registry: MessageCatalog::create(),
            clock: new SystemClock(),
            logger: new NullLogger(),
        );

        $client = ARCPClient::withConfig($config);
        self::assertSame($a, $client->session->transport);
    }
}
