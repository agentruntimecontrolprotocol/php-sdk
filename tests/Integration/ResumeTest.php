<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Store\EventLog;
use PHPUnit\Framework\TestCase;

/**
 * RFC §19 — message-id resume. Phase 5 only commits to walking the
 * EventLog from `after_message_id`. Checkpoint-based resume is deferred
 * to v0.2.
 */
final class ResumeTest extends TestCase
{
    public function testEventLogReplayProducesDeterministicOrder(): void
    {
        $registry = MessageCatalog::create();
        $serializer = new EnvelopeSerializer($registry);
        $log = EventLog::inMemory($serializer);
        $sess = new SessionId('sess_x');

        $ids = [];
        foreach (range(1, 6) as $i) {
            $env = new Envelope(
                id: new MessageId('msg_' . $i),
                payload: new EventEmit('demo', ['n' => $i]),
                timestamp: new \DateTimeImmutable('now'),
                sessionId: $sess,
            );
            $log->append($env);
            $ids[] = (string) $env->id;
        }

        unset($env);

        $replay1 = [];
        foreach ($log->replayAfter('') as $past1) {
            $replay1[] = (string) $past1->id;
        }
        self::assertSame($ids, $replay1);

        $replay2 = [];
        foreach ($log->replayAfter('msg_3') as $past2) {
            $replay2[] = (string) $past2->id;
        }
        self::assertSame(['msg_4', 'msg_5', 'msg_6'], $replay2);

        // Determinism: a second replay returns the same sequence.
        $replay3 = [];
        foreach ($log->replayAfter('') as $past3) {
            $replay3[] = (string) $past3->id;
        }
        self::assertSame($replay1, $replay3);
    }
}
