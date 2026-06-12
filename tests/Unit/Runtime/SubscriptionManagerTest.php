<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Amp\Cancellation;
use Arcp\Clock\FakeClock;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Envelope\Priority;
use Arcp\Errors\TransportClosedException;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Subscriptions\JobSubscribe;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Arcp\Runtime\Session;
use Arcp\Runtime\SubscriptionManager;
use Arcp\Transport\MemoryTransport;
use Arcp\Transport\Transport;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class SubscriptionManagerTest extends TestCase
{
    private function serializer(): EnvelopeSerializer
    {
        return new EnvelopeSerializer(MessageCatalog::create());
    }

    private function session(): Session
    {
        [$a] = MemoryTransport::pair();
        $session = new Session($a);
        $session->sessionId = SessionId::random();

        return $session;
    }

    private function envelopeFor(Session $session): Envelope
    {
        return new Envelope(
            id: MessageId::random(),
            payload: new SubscribeEvent(['hello' => 'world']),
            timestamp: new \DateTimeImmutable('2000-01-01T00:00:00Z'),
            sessionId: $session->sessionId,
        );
    }

    public function testDispatchStampsWrapperWithInjectedClock(): void
    {
        $captured = null;
        $clock = new FakeClock(new \DateTimeImmutable('2026-05-09T12:34:56Z'));
        $session = new Session(new class ($captured) implements Transport {
            public function __construct(private mixed &$captured)
            {
            }

            public function send(Envelope $env, ?Cancellation $c = null): void
            {
                $this->captured = $env;
            }

            public function receive(?Cancellation $c = null): ?Envelope
            {
                return null;
            }

            public function close(): void
            {
            }

            public function isClosed(): bool
            {
                return false;
            }
        });
        $session->sessionId = SessionId::random();

        $manager = new SubscriptionManager($this->serializer(), $clock);
        $manager->compile($session, new JobSubscribe([]));
        $manager->dispatch($this->envelopeFor($session));

        self::assertInstanceOf(Envelope::class, $captured);
        self::assertEquals($clock->now(), $captured->timestamp);
    }

    public function testDispatchClosesFailedSubscriberAfterIterationAndLogs(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $session = new Session(new class () implements Transport {
            public function send(Envelope $env, ?Cancellation $c = null): void
            {
                throw new TransportClosedException('boom');
            }

            public function receive(?Cancellation $c = null): ?Envelope
            {
                return null;
            }

            public function close(): void
            {
            }

            public function isClosed(): bool
            {
                return false;
            }
        });
        $session->sessionId = SessionId::random();

        $manager = new SubscriptionManager($this->serializer(), new FakeClock(), $logger);
        $manager->compile($session, new JobSubscribe([]));
        self::assertSame(1, $manager->count());

        $manager->dispatch($this->envelopeFor($session));

        self::assertSame(0, $manager->count(), 'failed subscriber is closed');
        self::assertNotEmpty($logger->warnings);
    }

    public function testFilterWithoutMinPriorityMatchesLowPriority(): void
    {
        $captured = [];
        $session = new Session(new class ($captured) implements Transport {
            /** @param list<Envelope> $captured */
            public function __construct(private array &$captured)
            {
            }

            public function send(Envelope $env, ?Cancellation $c = null): void
            {
                $this->captured[] = $env;
            }

            public function receive(?Cancellation $c = null): ?Envelope
            {
                return null;
            }

            public function close(): void
            {
            }

            public function isClosed(): bool
            {
                return false;
            }
        });
        $session->sessionId = SessionId::random();

        $manager = new SubscriptionManager($this->serializer(), new FakeClock());
        $manager->compile($session, new JobSubscribe([]));

        $low = new Envelope(
            id: MessageId::random(),
            payload: new SubscribeEvent(['k' => 'v']),
            timestamp: new \DateTimeImmutable('2000-01-01T00:00:00Z'),
            priority: Priority::Low,
            sessionId: $session->sessionId,
        );
        $manager->dispatch($low);
        self::assertCount(1, $captured);
    }
}
