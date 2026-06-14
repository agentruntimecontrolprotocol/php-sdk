<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Internal\Runtime;

use Amp\Cancellation;

use function Amp\delay;

use Amp\Future;
use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Client\ARCPClient;
use Arcp\Client\Handlers\HumanInputHandler;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\UnknownMessage;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Messages\Control\Interrupt;
use Arcp\Messages\Execution\JobCancel;
use Arcp\Messages\Execution\JobCancelled;
use Arcp\Messages\Execution\JobError;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Execution\JobSubmit;
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanChoiceResponse;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class LifecycleHandlerTest extends TestCase
{
    /** @return array{0: ARCPRuntime, 1: ARCPClient, 2: Future<mixed>} */
    private function pair(): array
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());
        return [$runtime, $client, $serverFuture];
    }

    public function testCancelUnknownJobReturnsJobNotFound(): void
    {
        [, $client, $serverFuture] = $this->pair();
        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new JobCancel(new JobId('job_does_not_exist')),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(JobError::class, $response);
        self::assertSame('JOB_NOT_FOUND', $response->error->code);

        $client->close();
        $serverFuture->await();
    }

    public function testCancelLiveJobYieldsJobCancelled(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair();
        $runtime->registerTool('slow', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                // Block until cancelled.
                $cancellation?->throwIfRequested();
                delay(0.5, cancellation: $cancellation);
                return null;
            }
        });

        // Start the slow tool without awaiting the result.
        $invokeId = MessageId::random();
        $invokeEnv = new Envelope(
            id: $invokeId,
            payload: new JobSubmit('slow'),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($invokeEnv);

        // Wait for the runtime to register the job.
        $jobId = null;
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $all = $runtime->jobs->all();
            if ($all !== []) {
                $jobId = $all[0]->id;
                break;
            }
            delay(0.01);
        }
        self::assertInstanceOf(JobId::class, $jobId);

        $cancelId = MessageId::random();
        $cancelEnv = new Envelope(
            id: $cancelId,
            payload: new JobCancel($jobId, 'user_aborted'),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($cancelEnv);
        $response = $client->pending->awaitResponse($cancelId, 5.0);
        // §7.4: the runtime acknowledges job.cancel with job.cancelled.
        self::assertInstanceOf(JobCancelled::class, $response);

        $client->close();
        $serverFuture->await();
    }

    public function testInterruptOnUnknownJobIsRejected(): void
    {
        [, $client, $serverFuture] = $this->pair();
        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new Interrupt('job', 'job_missing', 'help me'),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(JobError::class, $response);
        self::assertSame('JOB_NOT_FOUND', $response->error->code);

        $client->close();
        $serverFuture->await();
    }

    public function testInterruptOnLiveJobEmitsHumanInputAndAck(): void
    {
        [$runtime, $client, $serverFuture] = $this->pair();
        $runtime->registerTool('slow', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                delay(0.4, cancellation: $cancellation);
                return null;
            }
        });

        // Capture HumanInputRequest envelopes by overriding the human handler.
        $sawHumanInput = false;
        $client->humanInputHandler = new class ($sawHumanInput) implements HumanInputHandler {
            public function __construct(public bool &$saw)
            {
            }

            #[\Override]
            public function onInputRequest(HumanInputRequest $req): HumanInputResponse
            {
                $this->saw = true;
                return new HumanInputResponse(['note' => 'k'], 'user', new \DateTimeImmutable());
            }

            #[\Override]
            public function onChoiceRequest(HumanChoiceRequest $req): HumanChoiceResponse
            {
                return new HumanChoiceResponse(
                    $req->options[0]['id'] ?? '0',
                    'user',
                    new \DateTimeImmutable(),
                );
            }
        };

        // Start tool.
        $invokeId = MessageId::random();
        $client->session->transport->send(new Envelope(
            id: $invokeId,
            payload: new JobSubmit('slow'),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        ));

        // Wait for job registration.
        $jobId = null;
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $all = $runtime->jobs->all();
            if ($all !== []) {
                $jobId = $all[0]->id;
                break;
            }
            delay(0.01);
        }
        self::assertInstanceOf(JobId::class, $jobId);

        // Send interrupt.
        $intId = MessageId::random();
        $client->session->transport->send(new Envelope(
            id: $intId,
            payload: new Interrupt('job', (string) $jobId, ''),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        ));
        $response = $client->pending->awaitResponse($intId, 5.0);
        self::assertInstanceOf(JobEvent::class, $response);
        self::assertSame('status', $response->eventKind);
        self::assertSame('interrupt_accepted', $response->body['phase'] ?? null);

        // Give the HumanInputRequest a moment to arrive at the client.
        delay(0.05);
        self::assertTrue($sawHumanInput, 'expected human input request to arrive at the client');

        $client->close();
        $serverFuture->await();
    }

    public function testLeaseRefreshIsRejectedAsUnsupported(): void
    {
        // §9.5: renewal is NOT supported. The wire type is no longer in the
        // catalog; a legacy client's lease.refresh arrives as an unknown
        // message and is answered with INVALID_REQUEST, not silently dropped.
        [, $client, $serverFuture] = $this->pair();

        $msgId = MessageId::random();
        $env = new Envelope(
            id: $msgId,
            payload: new UnknownMessage('lease.refresh', [
                'lease_id' => 'lease_x',
                'extend_seconds' => 300,
            ]),
            timestamp: new \DateTimeImmutable(),
            sessionId: $client->session->sessionId,
        );
        $client->session->transport->send($env);
        $response = $client->pending->awaitResponse($msgId, 5.0);
        self::assertInstanceOf(JobError::class, $response);
        self::assertSame('INVALID_REQUEST', $response->error->code);
        self::assertStringContainsString('renewal', $response->error->message);

        $client->close();
        $serverFuture->await();
    }
}
