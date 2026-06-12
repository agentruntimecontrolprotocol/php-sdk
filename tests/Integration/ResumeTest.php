<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Session\SessionResume;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Store\EventLog;
use Arcp\Transport\MemoryTransport;
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

    public function testReplayAfterForSessionScopedToOneSession(): void
    {
        $registry = MessageCatalog::create();
        $serializer = new EnvelopeSerializer($registry);
        $log = EventLog::inMemory($serializer);

        $sessA = new SessionId('sess_a');
        $sessB = new SessionId('sess_b');

        $i = 0;
        foreach ([$sessA, $sessB, $sessA, $sessB, $sessA] as $sid) {
            ++$i;
            $log->append(new Envelope(
                id: new MessageId('msg_' . $i),
                payload: new EventEmit('demo', ['n' => $i]),
                timestamp: new \DateTimeImmutable('now'),
                sessionId: $sid,
            ));
        }

        $aReplay = [];
        foreach ($log->replayAfterForSession('', $sessA) as $env) {
            $aReplay[] = (string) $env->id;
        }
        self::assertSame(['msg_1', 'msg_3', 'msg_5'], $aReplay);

        $bReplay = [];
        foreach ($log->replayAfterForSession('', $sessB) as $past) {
            $bReplay[] = (string) $past->id;
        }
        self::assertSame(['msg_2', 'msg_4'], $bReplay);
    }

    public function testReplayAfterForSessionRejectsCrossSessionAfterId(): void
    {
        $registry = MessageCatalog::create();
        $serializer = new EnvelopeSerializer($registry);
        $log = EventLog::inMemory($serializer);

        $sessA = new SessionId('sess_a');
        $sessB = new SessionId('sess_b');
        $log->append(new Envelope(
            id: new MessageId('m_a1'),
            payload: new EventEmit('demo'),
            timestamp: new \DateTimeImmutable('now'),
            sessionId: $sessA,
        ));

        $this->expectException(InvalidRequestException::class);
        // Drain the generator; the exception fires on the prelude check.
        iterator_to_array($log->replayAfterForSession('m_a1', $sessB));
    }

    public function testResumeOnlyReplaysCallingSessionEnvelopes(): void
    {
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new NoneAuth('public')]),
        );
        [$serverTA, $clientTA] = MemoryTransport::pair();
        [$serverTB, $clientTB] = MemoryTransport::pair();
        $serverFutureA = $runtime->serveAsync($serverTA);
        $serverFutureB = $runtime->serveAsync($serverTB);

        $clientA = new ARCPClient($clientTA);
        $clientB = new ARCPClient($clientTB);
        $clientA->open(Auth::none(), new PeerInfo('cli-a', '0.1'), new Capabilities(anonymous: true));
        $clientB->open(Auth::none(), new PeerInfo('cli-b', '0.1'), new Capabilities(anonymous: true));

        // Both sessions push some events that get recorded in the event log.
        $clientA->ping();
        $clientB->ping();
        $clientA->ping();

        // Session A asks for a full replay from the beginning. The bug
        // pre-fix would forward session B's envelopes too.
        $resumeEnv = new Envelope(
            id: MessageId::random(),
            payload: new SessionResume(afterMessageId: ''),
            timestamp: new \DateTimeImmutable('now'),
            sessionId: $clientA->session->sessionId,
        );
        $clientTA->send($resumeEnv);

        $foreignSessionId = (string) $clientB->session->sessionId;
        $sawForeign = false;
        $sawAck = false;
        $loops = 0;
        while (!$sawAck && $loops < 200) {
            ++$loops;
            $env = $clientTA->receive();
            if (!$env instanceof Envelope) {
                break;
            }
            if (
                $env->sessionId instanceof SessionId
                && (string) $env->sessionId === $foreignSessionId
            ) {
                $sawForeign = true;
            }
            if (
                $env->correlationId instanceof MessageId
                && (string) $env->correlationId === (string) $resumeEnv->id
            ) {
                $sawAck = true;
            }
        }

        self::assertTrue($sawAck, 'expected SessionResume ack from runtime');
        self::assertFalse($sawForeign, 'resume must not leak envelopes from another session');

        $clientA->close();
        $clientB->close();
        $serverFutureA->await();
        $serverFutureB->await();
    }
}
