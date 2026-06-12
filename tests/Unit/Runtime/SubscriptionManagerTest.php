<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Amp\Cancellation;
use Arcp\Clock\FakeClock;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Envelope\Priority;
use Arcp\Errors\TransportClosedException;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Subscriptions\JobSubscribe;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Arcp\Runtime\Session;
use Arcp\Runtime\SubscriptionManager;
use Arcp\Transport\Transport;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class SubscriptionManagerTest extends TestCase
{
    private function serializer(): EnvelopeSerializer
    {
        return new EnvelopeSerializer(MessageCatalog::create());
    }

    private function jobId(): JobId
    {
        return new JobId('job_filter');
    }

    private function envelopeFor(Session $session): Envelope
    {
        return new Envelope(
            id: MessageId::random(),
            payload: new SubscribeEvent(['hello' => 'world']),
            timestamp: new \DateTimeImmutable('2000-01-01T00:00:00Z'),
            sessionId: $session->sessionId,
            jobId: $this->jobId(),
        );
    }

    public function testDispatchStampsWrapperWithInjectedClock(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-05-09T12:34:56Z'));
        $transport = new class () implements Transport {
            public ?Envelope $captured = null;

            #[\Override]
            public function send(Envelope $env, ?Cancellation $cancellation = null): void
            {
                $this->captured = $env;
            }

            #[\Override]
            public function receive(?Cancellation $cancellation = null): ?Envelope
            {
                return null;
            }

            #[\Override]
            public function close(): void
            {
            }

            #[\Override]
            public function isClosed(): bool
            {
                return false;
            }
        };
        $session = new Session($transport);
        $session->sessionId = SessionId::random();

        $manager = new SubscriptionManager($this->serializer(), $clock);
        $manager->compile($session, new JobSubscribe($this->jobId()));
        $manager->dispatch($this->envelopeFor($session));

        $captured = $transport->captured;
        self::assertInstanceOf(Envelope::class, $captured);
        self::assertEquals($clock->now(), $captured->timestamp);
    }

    public function testDispatchClosesFailedSubscriberAfterIterationAndLogs(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $session = new Session(new class () implements Transport {
            #[\Override]
            public function send(Envelope $env, ?Cancellation $cancellation = null): void
            {
                throw new TransportClosedException('boom');
            }

            #[\Override]
            public function receive(?Cancellation $cancellation = null): ?Envelope
            {
                return null;
            }

            #[\Override]
            public function close(): void
            {
            }

            #[\Override]
            public function isClosed(): bool
            {
                return false;
            }
        });
        $session->sessionId = SessionId::random();

        $manager = new SubscriptionManager($this->serializer(), new FakeClock(), $logger);
        $manager->compile($session, new JobSubscribe($this->jobId()));
        self::assertSame(1, $manager->count());

        $manager->dispatch($this->envelopeFor($session));

        self::assertSame(0, $manager->count(), 'failed subscriber is closed');
        self::assertNotEmpty($logger->warnings);
    }

    public function testFilterWithoutMinPriorityMatchesLowPriority(): void
    {
        $transport = new class () implements Transport {
            /** @var list<Envelope> */
            public array $captured = [];

            #[\Override]
            public function send(Envelope $env, ?Cancellation $cancellation = null): void
            {
                $this->captured[] = $env;
            }

            #[\Override]
            public function receive(?Cancellation $cancellation = null): ?Envelope
            {
                return null;
            }

            #[\Override]
            public function close(): void
            {
            }

            #[\Override]
            public function isClosed(): bool
            {
                return false;
            }
        };
        $session = new Session($transport);
        $session->sessionId = SessionId::random();

        $manager = new SubscriptionManager($this->serializer(), new FakeClock());
        $manager->compile($session, new JobSubscribe($this->jobId()));

        $low = new Envelope(
            id: MessageId::random(),
            payload: new SubscribeEvent(['k' => 'v']),
            timestamp: new \DateTimeImmutable('2000-01-01T00:00:00Z'),
            priority: Priority::Low,
            sessionId: $session->sessionId,
            jobId: $this->jobId(),
        );
        $manager->dispatch($low);
        self::assertCount(1, $transport->captured);
    }
}
