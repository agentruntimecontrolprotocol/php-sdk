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
use Arcp\Messages\Artifacts\ArtifactFetch;
use Arcp\Messages\Artifacts\ArtifactPut;
use Arcp\Messages\Artifacts\ArtifactRef;
use Arcp\Messages\Artifacts\ArtifactRelease;
use Arcp\Messages\Control\Ack;
use Arcp\Messages\Control\Backpressure;
use Arcp\Messages\Execution\JobCancel;
use Arcp\Messages\Control\CancelAccepted;
use Arcp\Messages\Control\CancelRefused;
use Arcp\Messages\Control\CheckpointCreate;
use Arcp\Messages\Control\CheckpointRestore;
use Arcp\Messages\Control\Interrupt;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Session\SessionPing;
use Arcp\Messages\Session\SessionPong;
use Arcp\Messages\Session\SessionResume;
use Arcp\Messages\Execution\AgentDelegate;
use Arcp\Messages\Execution\AgentHandoff;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobCancelled;
use Arcp\Messages\Execution\JobCheckpoint;
use Arcp\Messages\Execution\JobCompleted;
use Arcp\Messages\Execution\JobFailed;
use Arcp\Messages\Execution\JobHeartbeat;
use Arcp\Messages\Execution\JobProgress;
use Arcp\Messages\Execution\JobSchedule;
use Arcp\Messages\Execution\JobStarted;
use Arcp\Messages\Execution\ResultChunk;
use Arcp\Messages\Execution\ResultChunkEncoding;
use Arcp\Messages\Execution\ToolError;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Messages\Execution\ToolResult;
use Arcp\Messages\Execution\WorkflowComplete;
use Arcp\Messages\Execution\WorkflowStart;
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanChoiceResponse;
use Arcp\Messages\Human\HumanInputCancelled;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;
use Arcp\Messages\Permissions\LeaseExtended;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Messages\Permissions\LeaseRefresh;
use Arcp\Messages\Permissions\LeaseRevoked;
use Arcp\Messages\Permissions\PermissionDeny;
use Arcp\Messages\Permissions\PermissionGrant;
use Arcp\Messages\Permissions\PermissionRequest;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\Jobs;
use Arcp\Messages\Session\ListJobs;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Session\SessionWelcome;
use Arcp\Messages\Session\SessionAuthenticate;
use Arcp\Messages\Session\SessionChallenge;
use Arcp\Messages\Session\SessionClose;
use Arcp\Messages\Session\SessionEvicted;
use Arcp\Messages\Session\SessionHello;
use Arcp\Messages\Session\SessionRefresh;
use Arcp\Messages\Session\SessionRejected;
use Arcp\Messages\Session\SessionUnauthenticated;
use Arcp\Messages\Streaming\StreamChunk;
use Arcp\Messages\Streaming\StreamClose;
use Arcp\Messages\Streaming\StreamError;
use Arcp\Messages\Streaming\StreamKind;
use Arcp\Messages\Streaming\StreamOpen;
use Arcp\Messages\Subscriptions\JobSubscribe;
use Arcp\Messages\Subscriptions\JobSubscribed;
use Arcp\Messages\Subscriptions\SubscribeClosed;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Arcp\Messages\Subscriptions\JobUnsubscribe;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Messages\Telemetry\LogEvent;
use Arcp\Messages\Telemetry\MetricEvent;
use Arcp\Messages\Telemetry\TraceSpan;
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
        $err = new ErrorPayload('INTERNAL', 'something went wrong', true);

        yield 'session.open' => [new SessionHello(
            Auth::bearer('t'),
            new PeerInfo('cli', '0.1', principal: 'p'),
            new Capabilities(streaming: true),
        )];
        yield 'session.challenge' => [new SessionChallenge('chal-token-123')];
        yield 'session.authenticate' => [new SessionAuthenticate(Auth::bearer('t'))];
        yield 'session.accepted' => [new SessionWelcome(
            new SessionId('sess_x'),
            Capabilities::defaultRuntime(),
            new PeerInfo('rt', '1.0', trustLevel: 'trusted'),
            $now->modify('+1 hour'),
        )];
        yield 'session.unauthenticated' => [new SessionUnauthenticated($err)];
        yield 'session.rejected' => [new SessionRejected($err)];
        yield 'session.refresh' => [new SessionRefresh($now->modify('+5 minutes'), 'token rotation')];
        yield 'session.list_jobs' => [new ListJobs(['agent' => 'planner@1.0.0'], 10, 'job_prev')];
        yield 'session.jobs' => [new Jobs('msg_req', [[
            'job_id' => 'job_x',
            'agent' => 'planner@1.0.0',
            'status' => 'running',
            'created_at' => '2026-05-09T12:00:00Z',
        ]], null)];
        yield 'session.evicted' => [new SessionEvicted('idle timeout', 'IDLE')];
        yield 'session.close' => [new SessionClose('client_close')];

        yield 'ping' => [new SessionPing('nonce-123')];
        yield 'pong' => [new SessionPong('nonce-123')];
        yield 'ack' => [new Ack('replay')];
        yield 'nack' => [new Nack($err)];
        yield 'cancel' => [new JobCancel('job', 'job_x', 'aborted', 5000)];
        yield 'cancel.accepted' => [new CancelAccepted(5000)];
        yield 'cancel.refused' => [new CancelRefused('not_cancellable')];
        yield 'interrupt' => [new Interrupt('job', 'job_x', 'pause and ask')];
        yield 'resume' => [new SessionResume('msg_xyz', null, true)];
        yield 'backpressure' => [new Backpressure(20, 65536, 'render queue full')];
        yield 'checkpoint.create' => [new CheckpointCreate(['cursor' => 12])];
        yield 'checkpoint.restore' => [new CheckpointRestore('chk_001')];

        yield 'tool.invoke' => [new ToolInvoke('search', ['q' => '*.ts'])];
        yield 'tool.result' => [new ToolResult(['hits' => 5])];
        yield 'tool.error' => [new ToolError($err)];
        yield 'job.accepted' => [new JobAccepted('queued')];
        yield 'job.started' => [new JobStarted($now)];
        yield 'job.progress' => [new JobProgress(50, 'midway')];
        yield 'job.result_chunk' => [new ResultChunk('res_x', 0, 'hello', ResultChunkEncoding::Utf8, true)];
        yield 'job.heartbeat' => [new JobHeartbeat(17, 60000, 'running')];
        yield 'job.checkpoint' => [new JobCheckpoint('chk_a', ['progress' => 50])];
        yield 'job.completed' => [new JobCompleted(['ok' => true])];
        yield 'job.failed' => [new JobFailed($err)];
        yield 'job.cancelled' => [new JobCancelled('user_aborted', 'CANCELLED')];
        yield 'job.schedule' => [new JobSchedule(
            ['type' => 'tool.invoke'],
            ['at' => '2026-05-10T13:00:00Z'],
        )];
        yield 'workflow.start' => [new WorkflowStart(['name' => 'demo'])];
        yield 'workflow.complete' => [new WorkflowComplete(['ok' => true])];
        yield 'agent.delegate' => [new AgentDelegate(['target' => 'research'])];
        yield 'agent.handoff' => [new AgentHandoff(['to' => 'rt2'])];

        yield 'stream.open.text' => [new StreamOpen(StreamKind::Text, 'text/plain', 'utf-8')];
        yield 'stream.open.thought' => [new StreamOpen(StreamKind::Thought)];
        yield 'stream.chunk.text' => [new StreamChunk(0, contentType: 'text/plain', content: 'hello')];
        yield 'stream.chunk.binary' => [new StreamChunk(1, data: 'base64data==', contentType: 'application/octet-stream')];
        yield 'stream.chunk.thought' => [new StreamChunk(2, role: 'assistant_thought', content: 'considering', redacted: false)];
        yield 'stream.close' => [new StreamClose(42)];
        yield 'stream.error' => [new StreamError($err)];

        yield 'human.input.request' => [new HumanInputRequest(
            'pick a branch',
            ['type' => 'object'],
            $now->modify('+5 minutes'),
            ['branch' => 'fix/auto'],
        )];
        yield 'human.input.response' => [new HumanInputResponse(['branch' => 'main'], 'cli', $now)];
        yield 'human.choice.request' => [new HumanChoiceRequest(
            'choose',
            [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']],
            $now->modify('+5 minutes'),
        )];
        yield 'human.choice.response' => [new HumanChoiceResponse('a', 'cli', $now)];
        yield 'human.input.cancelled' => [new HumanInputCancelled('DEADLINE_EXCEEDED', 'expired')];

        yield 'permission.request' => [new PermissionRequest('p', 'r', 'op', 'reason', 60)];
        yield 'permission.grant' => [new PermissionGrant('p', 'r', 'op', 60)];
        yield 'permission.deny' => [new PermissionDeny('p', 'r', 'op', 'policy')];
        yield 'lease.granted' => [new LeaseGranted(
            new LeaseId('lease_x'),
            'p',
            'r',
            'op',
            $now->modify('+5 minutes'),
        )];
        yield 'lease.extended' => [new LeaseExtended(new LeaseId('lease_x'), $now->modify('+10 minutes'))];
        yield 'lease.revoked' => [new LeaseRevoked(new LeaseId('lease_x'), 'policy_violation')];
        yield 'lease.refresh' => [new LeaseRefresh(new LeaseId('lease_x'), 60)];

        yield 'subscribe' => [new JobSubscribe(['types' => ['log']], 'msg_after')];
        yield 'subscribe.accepted' => [new JobSubscribed(new SubscriptionId('sub_x'))];
        yield 'subscribe.event' => [new SubscribeEvent(['type' => 'log', 'arcp' => '1.1', 'id' => 'msg_inner', 'timestamp' => '2026-05-09T12:00:00Z', 'payload' => ['level' => 'info', 'message' => 'hi']])];
        yield 'unsubscribe' => [new JobUnsubscribe()];
        yield 'subscribe.closed' => [new SubscribeClosed('UNAVAILABLE', 'shutdown')];

        yield 'artifact.put' => [new ArtifactPut('text/plain', 'aGVsbG8=', 60, 'sha256-deadbeef')];
        yield 'artifact.fetch' => [new ArtifactFetch(new ArtifactId('art_x'))];
        yield 'artifact.ref' => [new ArtifactRef(
            new ArtifactId('art_x'),
            'arcp://session/sess_x/artifact/art_x',
            'text/plain',
            5,
            'sha256-deadbeef',
            $now->modify('+24 hours'),
        )];
        yield 'artifact.release' => [new ArtifactRelease(new ArtifactId('art_x'))];

        yield 'event.emit' => [new EventEmit('demo', ['count' => 1])];
        yield 'log' => [new LogEvent('warn', 'retrying', ['attempt' => 2])];
        yield 'metric' => [new MetricEvent('tokens.used', 1432, 'tokens', ['kind' => 'input'])];
        yield 'trace.span' => [new TraceSpan(
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
