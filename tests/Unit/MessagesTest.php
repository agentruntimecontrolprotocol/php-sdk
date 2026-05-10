<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Messages\Telemetry\EventEmit;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 smoke covers `event.emit` only — the rest of the message-type
 * catalog is registered in Phase 2 and exercised there.
 */
final class MessagesTest extends TestCase
{
    public function testEventEmitRoundTrip(): void
    {
        $msg = new EventEmit('subscription.backfill_complete', ['count' => 12]);
        $arr = $msg->toArray();
        self::assertSame('subscription.backfill_complete', $arr['type']);
        self::assertArrayHasKey('attributes', $arr);
        $attributes = $arr['attributes'];
        self::assertIsArray($attributes);
        self::assertSame(12, $attributes['count']);

        $back = EventEmit::fromArray($arr);
        self::assertEquals($msg, $back);
    }

    public function testEventEmitOmitsEmptyAttributes(): void
    {
        $msg = new EventEmit('demo');
        self::assertSame(['type' => 'demo'], $msg->toArray());
    }

    public function testEventEmitRejectsEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EventEmit('');
    }

    public function testEventEmitFromArrayRequiresType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventEmit::fromArray([]);
    }

    public function testEventEmitFromArrayRejectsBadAttributes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventEmit::fromArray(['type' => 'demo', 'attributes' => 'not-an-object']);
    }

    public function testRegistryRejectsDuplicateRegistration(): void
    {
        $registry = new MessageTypeRegistry();
        $registry->register(EventEmit::class);
        $this->expectException(InvalidArgumentException::class);
        $registry->register(EventEmit::class);
    }

    public function testRegistryClassFor(): void
    {
        $registry = new MessageTypeRegistry();
        $registry->register(EventEmit::class);
        self::assertSame(EventEmit::class, $registry->classFor('event.emit'));
        self::assertNull($registry->classFor('not.registered'));
    }

    public function testRegistryHasAndListTypes(): void
    {
        $registry = new MessageTypeRegistry();
        self::assertSame([], $registry->listTypes());
        self::assertFalse($registry->has('event.emit'));

        $registry->register(EventEmit::class);
        self::assertTrue($registry->has('event.emit'));
        self::assertSame(['event.emit'], $registry->listTypes());
    }

    public function testRegistryRejectsNonMessageTypeClass(): void
    {
        $registry = new MessageTypeRegistry();
        $this->expectException(InvalidArgumentException::class);
        $registry->register(\stdClass::class);
    }
}
