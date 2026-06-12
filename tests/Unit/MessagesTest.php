<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Errors\InvalidRequestException;
use Arcp\Messages\Execution\JobEvent;
use PHPUnit\Framework\TestCase;

/**
 * Smoke coverage for the §8.1 `job.event` shape and the message-type
 * registry; the full catalog is exercised by the round-trip suite.
 */
final class MessagesTest extends TestCase
{
    public function testJobEventRoundTrip(): void
    {
        $ts = new \DateTimeImmutable('2026-05-09T12:00:00Z');
        $msg = new JobEvent('status', $ts, ['phase' => 'backfill_complete', 'count' => 12]);
        $arr = $msg->toArray();
        self::assertSame('status', $arr['kind']);
        self::assertArrayHasKey('body', $arr);
        $body = $arr['body'];
        self::assertIsArray($body);
        self::assertSame(12, $body['count']);

        $back = JobEvent::fromArray($arr);
        self::assertEquals($msg->body, $back->body);
        self::assertSame($msg->eventKind, $back->eventKind);
    }

    public function testJobEventRejectsEmptyKind(): void
    {
        $this->expectException(InvalidRequestException::class);
        new JobEvent('', new \DateTimeImmutable());
    }

    public function testJobEventFromArrayRequiresKind(): void
    {
        $this->expectException(InvalidRequestException::class);
        JobEvent::fromArray(['ts' => '2026-05-09T12:00:00Z']);
    }

    public function testJobEventFromArrayRejectsBadBody(): void
    {
        $this->expectException(InvalidRequestException::class);
        JobEvent::fromArray(['kind' => 'status', 'ts' => '2026-05-09T12:00:00Z', 'body' => 'nope']);
    }

    public function testRegistryRejectsDuplicateRegistration(): void
    {
        $registry = new MessageTypeRegistry();
        $registry->register(JobEvent::class);
        $this->expectException(InvalidRequestException::class);
        $registry->register(JobEvent::class);
    }

    public function testRegistryClassFor(): void
    {
        $registry = new MessageTypeRegistry();
        $registry->register(JobEvent::class);
        self::assertSame(JobEvent::class, $registry->classFor('job.event'));
        self::assertNull($registry->classFor('not.registered'));
    }

    public function testRegistryHasAndListTypes(): void
    {
        $registry = new MessageTypeRegistry();
        self::assertSame([], $registry->listTypes());
        self::assertFalse($registry->has('job.event'));

        $registry->register(JobEvent::class);
        self::assertTrue($registry->has('job.event'));
        self::assertSame(['job.event'], $registry->listTypes());
    }

    public function testRegistryRejectsNonMessageTypeClass(): void
    {
        $registry = new MessageTypeRegistry();
        $this->expectException(InvalidRequestException::class);
        $registry->register(\stdClass::class);
    }
}
