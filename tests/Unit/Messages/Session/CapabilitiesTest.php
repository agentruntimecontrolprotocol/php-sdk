<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Messages\Session;

use Arcp\Errors\InvalidRequestException;
use Arcp\Messages\Session\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    public function testDefaultsAreSpecShape(): void
    {
        // §6.2: capabilities are `{encodings, features}` (+ `agents` in
        // session.welcome only).
        $caps = new Capabilities();
        self::assertSame(
            ['encodings' => ['json'], 'features' => []],
            $caps->toArray(),
        );
    }

    public function testEmptyEncodingsRejected(): void
    {
        $this->expectException(InvalidRequestException::class);
        new Capabilities(encodings: []);
    }

    public function testRoundTripWithAgents(): void
    {
        $caps = new Capabilities(
            encodings: ['json'],
            features: ['heartbeat', 'subscribe'],
            agents: [['name' => 'echo', 'versions' => ['1.0.0'], 'default' => '1.0.0']],
        );
        $decoded = Capabilities::fromArray($caps->toArray());
        self::assertSame($caps->toArray(), $decoded->toArray());
    }

    public function testIntersectKeepsRuntimeOrderAndAgents(): void
    {
        $advertised = new Capabilities(
            features: ['heartbeat', 'ack', 'subscribe'],
            agents: [['name' => 'echo', 'versions' => []]],
        );
        $requested = new Capabilities(features: ['subscribe', 'heartbeat', 'checkpoints']);
        $effective = $advertised->intersect($requested);
        self::assertSame(['heartbeat', 'subscribe'], $effective->features);
        self::assertSame([['name' => 'echo', 'versions' => []]], $effective->agents);
    }

    public function testVendorExtraKeysRoundTrip(): void
    {
        $caps = Capabilities::fromArray([
            'encodings' => ['json'],
            'features' => [],
            'arcpx.market.cost_per_mtok.v1' => 4.5,
        ]);
        self::assertSame(4.5, $caps->extra['arcpx.market.cost_per_mtok.v1']);
        self::assertArrayHasKey('arcpx.market.cost_per_mtok.v1', $caps->toArray());
    }
}
