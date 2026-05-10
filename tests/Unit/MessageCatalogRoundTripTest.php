<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Envelope\MessageCatalog;
use Arcp\Envelope\MessageType;
use Arcp\Errors\ErrorPayload;
use Arcp\Ids\ArtifactId;
use Arcp\Ids\LeaseId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SubscriptionId;
use Arcp\Messages\Artifacts;
use Arcp\Messages\Control;
use Arcp\Messages\Execution;
use Arcp\Messages\Human;
use Arcp\Messages\Permissions;
use Arcp\Messages\Session;
use Arcp\Messages\Streaming;
use Arcp\Messages\Streaming\StreamKind;
use Arcp\Messages\Subscriptions;
use Arcp\Messages\Telemetry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip every message-type class through fromArray()/toArray() so
 * the wire shape stays consistent. The catalog has ~64 classes; this
 * test covers each at least once and asserts that the registered
 * type-name matches what the runtime would dispatch.
 */
final class MessageCatalogRoundTripTest extends TestCase
{
    public function testCatalogRegistersEverything(): void
    {
        $registry = MessageCatalog::create();
        foreach (MessageCatalog::classes() as $cls) {
            self::assertTrue($registry->has($cls::typeName()), 'missing: ' . $cls);
        }
    }

    /** @return iterable<string, array{0: MessageType}> */
    public static function specimens(): iterable
    {
        $now = new \DateTimeImmutable('2026-05-09T12:00:00Z');
        $err = new ErrorPayload('INTERNAL', 'something went wrong');

        yield 'session.open' => [new Session\SessionOpen(
            Session\Auth::bearer('t'),
            new Session\PeerInfo('cli', '0.1', principal: 'p'),
            new Session\Capabilities(streaming: true),
        )];
        yield 'session.challenge' => [new Session\SessionChallenge('chal-token-123')];
        yield 'session.authenticate' => [new Session\SessionAuthenticate(Session\Auth::bearer('t'))];
        yield 'session.accepted' => [new Session\SessionAccepted(
            new SessionId('sess_x'),
            Session\Capabilities::defaultRuntime(),
            new Session\PeerInfo('rt', '1.0', trustLevel: 'trusted'),
            $now->modify('+1 hour'),
        )];
        yield 'session.unauthenticated' => [new Session\SessionUnauthenticated($err)];
        yield 'session.rejected' => [new Session\SessionRejected($err)];
        yield 'session.refresh' => [new Session\SessionRefresh($now->modify('+5 minutes'), 'token rotation')];
        yield 'session.evicted' => [new Session\SessionEvicted('idle timeout', 'IDLE')];
        yield 'session.close' => [new Session\SessionClose('client_close')];

        yield 'ping' => [new Control\Ping('nonce-123')];
        yield 'pong' => [new Control\Pong('nonce-123')];
        yield 'ack' => [new Control\Ack('replay')];
        yield 'nack' => [new Control\Nack($err)];
        yield 'cancel' => [new Control\Cancel('job', 'job_x', 'aborted', 5000)];
        yield 'cancel.accepted' => [new Control\CancelAccepted(5000)];
        yield 'cancel.refused' => [new Control\CancelRefused('not_cancellable')];
        yield 'interrupt' => [new Control\Interrupt('job', 'job_x', 'pause and ask')];
        yield 'resume' => [new Control\Resume('msg_xyz', null, true)];
        yield 'backpressure' => [new Control\Backpressure(20, 65536, 'render queue full')];
        yield 'checkpoint.create' => [new Control\CheckpointCreate(['cursor' => 12])];
        yield 'checkpoint.restore' => [new Control\CheckpointRestore('chk_001')];

        yield 'tool.invoke' => [new Execution\ToolInvoke('search', ['q' => '*.ts'])];
        yield 'tool.result' => [new Execution\ToolResult(['hits' => 5])];
        yield 'tool.error' => [new Execution\ToolError($err)];
        yield 'job.accepted' => [new Execution\JobAccepted('queued')];
        yield 'job.started' => [new Execution\JobStarted($now)];
        yield 'job.progress' => [new Execution\JobProgress(50, 'midway')];
        yield 'job.heartbeat' => [new Execution\JobHeartbeat(17, 60000, 'running')];
        yield 'job.checkpoint' => [new Execution\JobCheckpoint('chk_a', ['progress' => 50])];
        yield 'job.completed' => [new Execution\JobCompleted(['ok' => true])];
        yield 'job.failed' => [new Execution\JobFailed($err)];
        yield 'job.cancelled' => [new Execution\JobCancelled('user_aborted', 'CANCELLED')];
        yield 'job.schedule' => [new Execution\JobSchedule(
            ['type' => 'tool.invoke'],
            ['at' => '2026-05-10T13:00:00Z'],
        )];
        yield 'workflow.start' => [new Execution\WorkflowStart(['name' => 'demo'])];
        yield 'workflow.complete' => [new Execution\WorkflowComplete(['ok' => true])];
        yield 'agent.delegate' => [new Execution\AgentDelegate(['target' => 'research'])];
        yield 'agent.handoff' => [new Execution\AgentHandoff(['to' => 'rt2'])];

        yield 'stream.open.text' => [new Streaming\StreamOpen(StreamKind::Text, 'text/plain', 'utf-8')];
        yield 'stream.open.thought' => [new Streaming\StreamOpen(StreamKind::Thought)];
        yield 'stream.chunk.text' => [new Streaming\StreamChunk(0, content: 'hello', contentType: 'text/plain')];
        yield 'stream.chunk.binary' => [new Streaming\StreamChunk(1, data: 'base64data==', contentType: 'application/octet-stream')];
        yield 'stream.chunk.thought' => [new Streaming\StreamChunk(2, role: 'assistant_thought', content: 'considering', redacted: false)];
        yield 'stream.close' => [new Streaming\StreamClose(42)];
        yield 'stream.error' => [new Streaming\StreamError($err)];

        yield 'human.input.request' => [new Human\HumanInputRequest(
            'pick a branch',
            ['type' => 'object'],
            $now->modify('+5 minutes'),
            ['branch' => 'fix/auto'],
        )];
        yield 'human.input.response' => [new Human\HumanInputResponse(['branch' => 'main'], 'cli', $now)];
        yield 'human.choice.request' => [new Human\HumanChoiceRequest(
            'choose',
            [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']],
            $now->modify('+5 minutes'),
        )];
        yield 'human.choice.response' => [new Human\HumanChoiceResponse('a', 'cli', $now)];
        yield 'human.input.cancelled' => [new Human\HumanInputCancelled('DEADLINE_EXCEEDED', 'expired')];

        yield 'permission.request' => [new Permissions\PermissionRequest('p', 'r', 'op', 'reason', 60)];
        yield 'permission.grant' => [new Permissions\PermissionGrant('p', 'r', 'op', 60)];
        yield 'permission.deny' => [new Permissions\PermissionDeny('p', 'r', 'op', 'policy')];
        yield 'lease.granted' => [new Permissions\LeaseGranted(
            new LeaseId('lease_x'),
            'p',
            'r',
            'op',
            $now->modify('+5 minutes'),
        )];
        yield 'lease.extended' => [new Permissions\LeaseExtended(new LeaseId('lease_x'), $now->modify('+10 minutes'))];
        yield 'lease.revoked' => [new Permissions\LeaseRevoked(new LeaseId('lease_x'), 'policy_violation')];
        yield 'lease.refresh' => [new Permissions\LeaseRefresh(new LeaseId('lease_x'), 60)];

        yield 'subscribe' => [new Subscriptions\Subscribe(['types' => ['log']], 'msg_after')];
        yield 'subscribe.accepted' => [new Subscriptions\SubscribeAccepted(new SubscriptionId('sub_x'))];
        yield 'subscribe.event' => [new Subscriptions\SubscribeEvent(['type' => 'log', 'arcp' => '1.0', 'id' => 'msg_inner', 'timestamp' => '2026-05-09T12:00:00Z', 'payload' => ['level' => 'info', 'message' => 'hi']])];
        yield 'unsubscribe' => [new Subscriptions\Unsubscribe()];
        yield 'subscribe.closed' => [new Subscriptions\SubscribeClosed('UNAVAILABLE', 'shutdown')];

        yield 'artifact.put' => [new Artifacts\ArtifactPut('text/plain', 'aGVsbG8=', 60, 'sha256-deadbeef')];
        yield 'artifact.fetch' => [new Artifacts\ArtifactFetch(new ArtifactId('art_x'))];
        yield 'artifact.ref' => [new Artifacts\ArtifactRef(
            new ArtifactId('art_x'),
            'arcp://session/sess_x/artifact/art_x',
            'text/plain',
            5,
            'sha256-deadbeef',
            $now->modify('+24 hours'),
        )];
        yield 'artifact.release' => [new Artifacts\ArtifactRelease(new ArtifactId('art_x'))];

        yield 'event.emit' => [new Telemetry\EventEmit('demo', ['count' => 1])];
        yield 'log' => [new Telemetry\LogEvent('warn', 'retrying', ['attempt' => 2])];
        yield 'metric' => [new Telemetry\MetricEvent('tokens.used', 1432, 'tokens', ['kind' => 'input'])];
        yield 'trace.span' => [new Telemetry\TraceSpan(
            'tool.invoke',
            $now,
            $now->modify('+1 second'),
            ['k' => 'v'],
            'ok',
        )];
    }

    #[DataProvider('specimens')]
    public function testRoundTrip(MessageType $msg): void
    {
        $arr = $msg->toArray();
        $cls = $msg::class;
        $back = $cls::fromArray($arr);
        self::assertEquals($msg, $back);
        self::assertSame($cls::typeName(), $back::typeName());
    }
}
