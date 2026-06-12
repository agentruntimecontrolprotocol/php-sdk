<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Clock\FakeClock;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Store\EventLog;
use Arcp\Store\IdempotencyRecord;
use PHPUnit\Framework\TestCase;

final class EventLogTest extends TestCase
{
    private FakeClock $clock;
    private EventLog $log;
    private SessionId $sess;

    #[\Override]
    protected function setUp(): void
    {
        $registry = new MessageTypeRegistry();
        $registry->register(EventEmit::class);
        $serializer = new EnvelopeSerializer($registry);
        $this->clock = new FakeClock(new \DateTimeImmutable('2026-05-09T12:00:00Z'));
        $this->log = EventLog::inMemory($serializer, $this->clock);
        $this->sess = new SessionId('sess_t');
    }

    private function envelope(string $id, string $type = 'subscription.backfill_complete'): Envelope
    {
        return new Envelope(
            id: new MessageId($id),
            payload: new EventEmit($type),
            timestamp: $this->clock->now(),
            sessionId: $this->sess,
        );
    }

    public function testAppendIsInsertOnce(): void
    {
        $env = $this->envelope('msg_a');
        self::assertTrue($this->log->append($env));
        self::assertFalse($this->log->append($env), 'second append must dedupe by id');
        self::assertSame(1, $this->log->count());
    }

    public function testHasMessageIdAfterAppend(): void
    {
        $this->log->append($this->envelope('msg_a'));
        self::assertTrue($this->log->hasMessageId('msg_a'));
        self::assertFalse($this->log->hasMessageId('msg_b'));
    }

    public function testReplayPreservesInsertionOrder(): void
    {
        foreach (['msg_1', 'msg_2', 'msg_3', 'msg_4'] as $id) {
            $this->log->append($this->envelope($id));
        }

        $ids = [];
        foreach ($this->log->replayAfter('') as $env) {
            $ids[] = (string) $env->id;
        }
        self::assertSame(['msg_1', 'msg_2', 'msg_3', 'msg_4'], $ids);
    }

    public function testReplayAfterMessageId(): void
    {
        foreach (['msg_1', 'msg_2', 'msg_3', 'msg_4'] as $id) {
            $this->log->append($this->envelope($id));
        }

        $ids = [];
        foreach ($this->log->replayAfter('msg_2') as $env) {
            $ids[] = (string) $env->id;
        }
        self::assertSame(['msg_3', 'msg_4'], $ids);
    }

    public function testReplayAfterUnknownMessageIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array($this->log->replayAfter('msg_does_not_exist'));
    }

    public function testReplayHonorsLimit(): void
    {
        foreach (['msg_1', 'msg_2', 'msg_3', 'msg_4'] as $id) {
            $this->log->append($this->envelope($id));
        }

        $ids = [];
        foreach ($this->log->replayAfter('', limit: 2) as $env) {
            $ids[] = (string) $env->id;
        }
        self::assertSame(['msg_1', 'msg_2'], $ids);
    }

    public function testSessionReplayExcludesInboundEnvelopes(): void
    {
        // Outbound runtime event, then an inbound client command, then
        // another outbound event — all for the same session.
        $this->log->append($this->envelope('out_1'), outbound: true);
        $this->log->append($this->envelope('in_1', 'tool.invoke'), outbound: false);
        $this->log->append($this->envelope('out_2'), outbound: true);

        $ids = [];
        foreach ($this->log->replayAfterForSession('', $this->sess) as $env) {
            $ids[] = (string) $env->id;
        }
        self::assertSame(['out_1', 'out_2'], $ids, 'resume must replay only outbound rows');
    }

    public function testInboundDedupStillWorks(): void
    {
        $env = $this->envelope('in_dup', 'tool.invoke');
        self::assertTrue($this->log->append($env, outbound: false));
        self::assertFalse($this->log->append($env, outbound: false), 'duplicate id must dedupe');
    }

    public function testIdempotencyCacheReturnsExistingOnRetry(): void
    {
        $expires = $this->clock->now()->modify('+1 hour');
        self::assertNull(
            $this->log->rememberIdempotent(
                new IdempotencyRecord('alice', 'refund-1', 'msg_outcome_a', $expires),
            ),
        );
        self::assertSame(
            'msg_outcome_a',
            $this->log->rememberIdempotent(
                new IdempotencyRecord('alice', 'refund-1', 'msg_outcome_b', $expires),
            ),
            'a second remember with the same key returns the prior outcome, not the new one',
        );
        self::assertSame('msg_outcome_a', $this->log->lookupIdempotent('alice', 'refund-1'));
    }

    public function testIdempotencyCacheExpiresLazily(): void
    {
        $expires = $this->clock->now()->modify('+5 seconds');
        $this->log->rememberIdempotent(
            new IdempotencyRecord('alice', 'refund-1', 'msg_x', $expires),
        );

        $this->clock->advance(10);

        self::assertNull(
            $this->log->lookupIdempotent('alice', 'refund-1'),
            'expired entry should not be returned',
        );
        // An expired entry is overwritten in place, so a fresh remember
        // succeeds with the new outcome.
        $newExpires = $this->clock->now()->modify('+1 hour');
        self::assertNull($this->log->rememberIdempotent(
            new IdempotencyRecord('alice', 'refund-1', 'msg_y', $newExpires),
        ));
        self::assertSame('msg_y', $this->log->lookupIdempotent('alice', 'refund-1'));
    }

    public function testIdempotencyCachePartitionsByPrincipal(): void
    {
        $expires = $this->clock->now()->modify('+1 hour');
        $this->log->rememberIdempotent(
            new IdempotencyRecord('alice', 'refund-1', 'msg_a', $expires),
        );
        $this->log->rememberIdempotent(
            new IdempotencyRecord('bob', 'refund-1', 'msg_b', $expires),
        );

        self::assertSame('msg_a', $this->log->lookupIdempotent('alice', 'refund-1'));
        self::assertSame('msg_b', $this->log->lookupIdempotent('bob', 'refund-1'));
    }

    public function testLookupIdempotentDoesNotDeleteExpiredRows(): void
    {
        $this->log->rememberIdempotent(
            new IdempotencyRecord('alice', 'refund-1', 'msg_x', $this->clock->now()->modify('+5 seconds')),
        );
        $this->clock->advance(10);

        // A read must not mutate the store: the expired row survives lookup
        // and is only removed by an explicit purge.
        self::assertNull($this->log->lookupIdempotent('alice', 'refund-1'));
        self::assertSame(1, $this->log->purgeExpiredIdempotent());
    }

    public function testPurgeExpiredIdempotentRemovesOnlyExpiredRows(): void
    {
        $this->log->rememberIdempotent(
            new IdempotencyRecord('alice', 'expired', 'msg_old', $this->clock->now()->modify('+5 seconds')),
        );
        $this->log->rememberIdempotent(
            new IdempotencyRecord('alice', 'live', 'msg_new', $this->clock->now()->modify('+1 hour')),
        );
        $this->clock->advance(10);

        self::assertSame(1, $this->log->purgeExpiredIdempotent());
        self::assertSame('msg_new', $this->log->lookupIdempotent('alice', 'live'));
    }
}
