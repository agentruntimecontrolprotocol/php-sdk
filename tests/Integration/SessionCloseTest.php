<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use function Amp\async;

use Amp\Cancellation;

use function Amp\delay;

use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Ids\MessageId;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Session\SessionClose;
use Arcp\Messages\Session\SessionClosed;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\JobState;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

/**
 * ARCP v1.1 §6.7 — graceful close. The runtime acknowledges with
 * `session.closed` before teardown, and in-flight jobs are NOT
 * affected: they continue running and remain resumable within the
 * resume window.
 */
final class SessionCloseTest extends TestCase
{
    public function testCloseIsAckedAndInFlightJobsSurvive(): void
    {
        $finished = new \ArrayObject(['done' => false]);
        $runtime = new ARCPRuntime();
        $runtime->registerTool('slow', new class ($finished) implements ToolHandler {
            /** @param \ArrayObject<string, bool> $finished */
            public function __construct(private readonly \ArrayObject $finished)
            {
            }

            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                delay(0.2);
                $this->finished['done'] = true;
                return ['done' => true];
            }
        });

        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        // Submit and give the runtime a beat to accept + start the fiber.
        $submission = async(static fn () => $client->invokeTool('slow', [], deadlineSeconds: 5.0));
        delay(0.05);
        self::assertCount(1, $runtime->jobs->all());

        // §6.7: session.close is acknowledged with session.closed (reason
        // echoed) before transport teardown.
        $closeId = MessageId::random();
        $clientT->send(new Envelope(
            id: $closeId,
            payload: new SessionClose('done for today'),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        ));
        $ack = $client->pending->awaitResponse($closeId, 5.0);
        self::assertInstanceOf(SessionClosed::class, $ack);
        self::assertSame('done for today', $ack->reason);

        // §6.7: the in-flight job was not cancelled by the close…
        $jobs = $runtime->jobs->all();
        self::assertCount(1, $jobs);
        self::assertFalse($jobs[0]->cancelRequested, 'close MUST NOT cancel in-flight jobs');
        self::assertSame(JobState::Running, $jobs[0]->state);

        // …and it runs to natural completion after the transport is gone.
        delay(0.3);
        self::assertTrue($finished['done'], 'job must finish naturally after session.close');
        self::assertSame(JobState::Success, $runtime->jobs->all()[0]->state);

        try {
            $submission->await();
        } catch (\Throwable) {
            // The transport dropped before the terminal arrived; the result
            // was buffered for resume instead.
        }
        $serverFuture->await();
    }
}
