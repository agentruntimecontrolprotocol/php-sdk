<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Amp\Cancellation;

use function Amp\delay;

use Arcp\Auth\AuthRouter;
use Arcp\Auth\BearerAuth;
use Arcp\Client\ARCPClient;
use Arcp\Errors\ResumeWindowExpiredException;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

/**
 * ARCP v1.1 §6.3 — token-based resume. The welcome carries a rotating
 * `resume_token` + `resume_window_sec`; a reconnect presents the token
 * and `last_event_seq` in `session.hello` and receives the buffered
 * events past that sequence before going live.
 */
final class ResumeTest extends TestCase
{
    public function testWelcomeCarriesResumeParameters(): void
    {
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $welcome = $client->open(
            Auth::anonymous(),
            new PeerInfo('cli', '0.1'),
            new Capabilities(features: ['heartbeat']),
        );

        self::assertNotNull($welcome->resumeToken);
        self::assertSame($runtime->resumeWindowSec, $welcome->resumeWindowSec);
        // §6.4: heartbeat negotiated -> interval present.
        self::assertSame($runtime->heartbeatIntervalSec, $welcome->heartbeatIntervalSec);

        $client->close();
        $serverFuture->await();
    }

    public function testHeartbeatIntervalOmittedWhenNotNegotiated(): void
    {
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $welcome = $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());
        self::assertNull($welcome->heartbeatIntervalSec);

        $client->close();
        $serverFuture->await();
    }

    public function testResumeReattachesSessionRotatesTokenAndReplays(): void
    {
        $runtime = $this->runtimeWithBearer(['t-alice' => 'alice']);
        $runtime->registerTool('echo', self::echoTool());

        [$serverT1, $clientT1] = MemoryTransport::pair();
        $future1 = $runtime->serveAsync($serverT1);
        $client1 = new ARCPClient($clientT1);
        $welcome1 = $client1->open(Auth::bearer('t-alice'), new PeerInfo('cli', '0.1'), new Capabilities());
        $client1->invokeTool('echo', ['n' => 1]);
        $lastSeen = $client1->session->lastReceivedEventSeq ?? 0;
        self::assertGreaterThan(0, $lastSeen, 'sequenced job messages must carry event_seq');

        // Unexpected transport drop (no session.close): session parks.
        $clientT1->close();
        $future1->await();

        // Reconnect presenting resume_token + last_event_seq = 0 so the
        // runtime replays everything still buffered.
        [$serverT2, $clientT2] = MemoryTransport::pair();
        $future2 = $runtime->serveAsync($serverT2);
        $client2 = new ARCPClient($clientT2);
        $token = $welcome1->resumeToken;
        self::assertNotNull($token);
        $welcome2 = $client2->open(
            Auth::bearer('t-alice'),
            new PeerInfo('cli', '0.1'),
            new Capabilities(),
            resumeToken: $token,
            lastEventSeq: 0,
        );

        // Same logical session; token rotated on the new welcome (§6.3).
        self::assertSame((string) $welcome1->sessionId, (string) $welcome2->sessionId);
        self::assertNotNull($welcome2->resumeToken);
        self::assertNotSame($token, $welcome2->resumeToken);

        // Replayed buffered events reach the new connection.
        delay(0.05);
        self::assertSame($lastSeen, $client2->session->lastReceivedEventSeq);

        // The resumed session stays live: a new invocation works.
        $result = $client2->invokeTool('echo', ['n' => 2]);
        self::assertSame(['n' => 2], $result->result);

        $client2->close();
        $future2->await();
    }

    public function testUnknownResumeTokenIsResumeWindowExpired(): void
    {
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        try {
            $client->open(
                Auth::anonymous(),
                new PeerInfo('cli', '0.1'),
                new Capabilities(),
                resumeToken: 'rt_does_not_exist',
                lastEventSeq: 0,
            );
            self::fail('expected ResumeWindowExpiredException');
        } catch (ResumeWindowExpiredException $e) {
            // §6.3: unknown/expired token -> RESUME_WINDOW_EXPIRED.
            self::assertStringContainsString('resume token', $e->getMessage());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testResumeByOtherPrincipalRejectedAndOwnerCanStillResume(): void
    {
        $runtime = $this->runtimeWithBearer(['t-alice' => 'alice', 't-bob' => 'bob']);
        $runtime->registerTool('echo', self::echoTool());

        [$serverT1, $clientT1] = MemoryTransport::pair();
        $future1 = $runtime->serveAsync($serverT1);
        $client1 = new ARCPClient($clientT1);
        $welcome1 = $client1->open(Auth::bearer('t-alice'), new PeerInfo('cli', '0.1'), new Capabilities());
        $token = $welcome1->resumeToken;
        self::assertNotNull($token);
        $clientT1->close();
        $future1->await();

        // Bob presents Alice's token: rejected, token stays valid for Alice.
        [$serverT2, $clientT2] = MemoryTransport::pair();
        $future2 = $runtime->serveAsync($serverT2);
        $mallory = new ARCPClient($clientT2);
        try {
            $mallory->open(
                Auth::bearer('t-bob'),
                new PeerInfo('cli', '0.1'),
                new Capabilities(),
                resumeToken: $token,
                lastEventSeq: 0,
            );
            self::fail('expected ResumeWindowExpiredException');
        } catch (ResumeWindowExpiredException) {
            // §6.3/§14: same-principal enforcement must not leak the session.
        }
        $mallory->close();
        $future2->await();

        [$serverT3, $clientT3] = MemoryTransport::pair();
        $future3 = $runtime->serveAsync($serverT3);
        $alice = new ARCPClient($clientT3);
        $welcome3 = $alice->open(
            Auth::bearer('t-alice'),
            new PeerInfo('cli', '0.1'),
            new Capabilities(),
            resumeToken: $token,
            lastEventSeq: 0,
        );
        self::assertSame((string) $welcome1->sessionId, (string) $welcome3->sessionId);

        $alice->close();
        $future3->await();
    }

    public function testResumeBeyondReleasedBufferIsResumeWindowExpired(): void
    {
        $runtime = $this->runtimeWithBearer(['t-alice' => 'alice']);
        $runtime->registerTool('echo', self::echoTool());

        [$serverT1, $clientT1] = MemoryTransport::pair();
        $future1 = $runtime->serveAsync($serverT1);
        $client1 = new ARCPClient($clientT1);
        $welcome1 = $client1->open(Auth::bearer('t-alice'), new PeerInfo('cli', '0.1'), new Capabilities());
        $client1->invokeTool('echo', ['n' => 1]);
        $lastSeen = $client1->session->lastReceivedEventSeq;
        self::assertNotNull($lastSeen);

        // §6.5: acknowledge everything; the runtime releases the buffer.
        $client1->ack($lastSeen);
        delay(0.05);
        $clientT1->close();
        $future1->await();

        // A resume claiming last_event_seq=0 needs events 1..N, which were
        // just released -> RESUME_WINDOW_EXPIRED (§6.3).
        [$serverT2, $clientT2] = MemoryTransport::pair();
        $future2 = $runtime->serveAsync($serverT2);
        $client2 = new ARCPClient($clientT2);
        $token = $welcome1->resumeToken;
        self::assertNotNull($token);
        try {
            $client2->open(
                Auth::bearer('t-alice'),
                new PeerInfo('cli', '0.1'),
                new Capabilities(),
                resumeToken: $token,
                lastEventSeq: 0,
            );
            self::fail('expected ResumeWindowExpiredException');
        } catch (ResumeWindowExpiredException) {
            // expected
        } finally {
            $client2->close();
            $future2->await();
        }
    }

    public function testHeartbeatTrafficIsNeitherSequencedNorBuffered(): void
    {
        $runtime = new ARCPRuntime();
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);

        $client = new ARCPClient($clientT);
        $client->open(
            Auth::anonymous(),
            new PeerInfo('cli', '0.1'),
            new Capabilities(features: ['heartbeat', 'ack']),
        );

        $before = $runtime->eventLog->count();
        $pong = $client->ping();
        $client->ack(0);
        delay(0.05);

        // §6.4/§6.5: ping/pong/ack never reach the event log or consume
        // event_seq.
        self::assertSame($before, $runtime->eventLog->count());
        self::assertNull($client->session->lastReceivedEventSeq);
        self::assertNotSame('', $pong->pingNonce);

        $client->close();
        $serverFuture->await();
    }

    /** @param array<string, string> $tokens */
    private function runtimeWithBearer(array $tokens): ARCPRuntime
    {
        return new ARCPRuntime(authRouter: new AuthRouter([new BearerAuth($tokens)]));
    }

    private static function echoTool(): ToolHandler
    {
        return new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                return $arguments;
            }
        };
    }
}
