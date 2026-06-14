<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use function Amp\async;

use Amp\Cancellation;

use function Amp\delay;

use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Client\ARCPClient;
use Arcp\Clock\FakeClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\BudgetExhaustedException;
use Arcp\Errors\InvalidRequestException;
use Arcp\Errors\LeaseExpiredException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Messages\Execution\JobCancel;
use Arcp\Messages\Execution\JobError;
use Arcp\Messages\Execution\JobResult;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Credentials\Credential;
use Arcp\Runtime\Credentials\InMemoryCredentialProvisioner;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Tests\Support\FakeDurableCredentialStore;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the spec-conformance audit fixes that are not
 * exercised by the topic-specific integration files: cancel authorization
 * (#58), lease expiry validation + enforcement (#60/#156), pre-dispatch
 * budget checks (#158), and startup credential revocation replay (#160).
 */
final class SpecConformanceAuditTest extends TestCase
{
    public function testCancelFromNonSubmittingSessionIsDeniedAndJobKeepsRunning(): void
    {
        // §7.4/§7.6/§14: cancellation is reserved for the submitting session.
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('slow', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                delay(0.3, cancellation: $cancellation);
                return ['ok' => true];
            }
        });

        [$serverA, $clientA] = MemoryTransport::pair();
        $serverFutureA = $runtime->serveAsync($serverA);
        $submitter = new ARCPClient($clientA);
        $submitter->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        [$serverB, $clientB] = MemoryTransport::pair();
        $serverFutureB = $runtime->serveAsync($serverB);
        $observer = new ARCPClient($clientB);
        $observer->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $future = async(fn () => $submitter->invokeTool('slow'));
        $deadline = microtime(true) + 2.0;
        while ($runtime->jobs->count() === 0 && microtime(true) < $deadline) {
            delay(0.01);
        }
        $job = $runtime->jobs->all()[0];

        $msgId = MessageId::random();
        $observer->session->transport->send(new Envelope(
            id: $msgId,
            payload: new JobCancel($job->id, 'stop'),
            timestamp: new \DateTimeImmutable(),
            sessionId: $observer->session->sessionId,
        ));
        $response = $observer->pending->awaitResponse($msgId, 5.0);

        self::assertInstanceOf(JobError::class, $response);
        self::assertSame('PERMISSION_DENIED', $response->error->code);
        self::assertFalse($job->state->isTerminal(), 'job must keep running after denied cancel');

        // The submitting session still completes normally.
        $result = $future->await();
        self::assertInstanceOf(JobResult::class, $result);
        self::assertSame(['ok' => true], $result->result);

        $submitter->close();
        $observer->close();
        $serverFutureA->await();
        $serverFutureB->await();
    }

    public function testPastExpiresAtIsRejectedAsInvalidRequest(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->submitWithLeaseConstraints(['expires_at' => '2000-01-01T00:00:00Z']);
    }

    public function testNonUtcExpiresAtIsRejectedAsInvalidRequest(): void
    {
        // §9.5: expires_at MUST be UTC with a Z suffix even when in the future.
        $this->expectException(InvalidRequestException::class);
        $this->submitWithLeaseConstraints(['expires_at' => '2099-01-01T00:00:00+02:00']);
    }

    public function testMalformedExpiresAtIsRejectedAsInvalidRequest(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->submitWithLeaseConstraints(['expires_at' => 'not-a-timestampZ']);
    }

    public function testLeaseExpiryDuringExecutionSurfacesLeaseExpired(): void
    {
        // §9.5: a job whose lease expires before an authority-bearing op
        // (here putArtifact / fs.write) receives LEASE_EXPIRED.
        $clock = new FakeClock();
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new AnonymousAuth()]),
            clock: $clock,
        );
        $runtime->registerTool('expiring', new class ($clock) implements ToolHandler {
            public function __construct(private readonly FakeClock $clock)
            {
            }

            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                // Advance past the lease's expires_at, then attempt an
                // authority-bearing operation.
                $this->clock->advance(120);
                $ctx->putArtifact('text/plain', 'data');
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $expiresAt = $clock->now()->modify('+60 seconds')->format('Y-m-d\\TH:i:s\\Z');
        $caught = null;
        try {
            $client->invokeTool('expiring', ['lease' => [
                'cost.budget' => ['USD:5.00'],
                'lease_constraints' => ['expires_at' => $expiresAt],
            ]]);
            self::fail('expected LeaseExpiredException');
        } catch (LeaseExpiredException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertInstanceOf(LeaseExpiredException::class, $caught);
    }

    public function testExhaustedBudgetFailsBeforeHandlerRuns(): void
    {
        // §9.6: a lease whose budget is already ≤ 0 fails with
        // BUDGET_EXHAUSTED before the handler fiber starts.
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $ran = false;
        $runtime->registerTool('budgeted', new class ($ran) implements ToolHandler {
            public function __construct(public bool &$ran)
            {
            }

            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $this->ran = true;
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $caught = null;
        try {
            $client->invokeTool('budgeted', ['lease' => ['cost.budget' => ['USD:0.00']]]);
            self::fail('expected BudgetExhaustedException');
        } catch (BudgetExhaustedException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertInstanceOf(BudgetExhaustedException::class, $caught);
        self::assertFalse($ran, 'handler fiber must not start when budget is exhausted');
    }

    public function testStartupReplaysOutstandingCredentialRevocations(): void
    {
        // §14: a durable store holding credentials from a prior runtime
        // instance has each revoked at the upstream on construction.
        $store = new FakeDurableCredentialStore();
        $store->add(new JobId('job_orphan'), new Credential(
            'cred_orphan',
            'bearer',
            'sk-virt-orphan',
            'memory://credentials',
        ));
        $provisioner = new InMemoryCredentialProvisioner();

        new ARCPRuntime(
            authRouter: new AuthRouter([new AnonymousAuth()]),
            credentialProvisioner: $provisioner,
            credentialStore: $store,
        );

        self::assertSame(['cred_orphan'], $provisioner->revoked);
        self::assertSame([], $store->outstanding());
    }

    public function testPaginationChainsAcrossPagesViaCursor(): void
    {
        // #114: the composite (created_at, id) cursor pages through the full
        // sorted inventory without skipping or repeating entries.
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('slow', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                delay(0.5, cancellation: $cancellation);
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities(features: ['list_jobs']));

        $futures = [];
        for ($i = 0; $i < 3; $i++) {
            $futures[] = async(fn () => $client->invokeTool('slow'));
        }
        $deadline = microtime(true) + 2.0;
        while ($runtime->jobs->count() < 3 && microtime(true) < $deadline) {
            delay(0.01);
        }
        self::assertSame(3, $runtime->jobs->count());

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $client->listJobs(['agent' => 'slow'], limit: 1, cursor: $cursor);
            foreach ($page->jobs as $entry) {
                $jobId = $entry['job_id'] ?? null;
                if (\is_string($jobId)) {
                    $seen[] = $jobId;
                }
            }
            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        self::assertCount(3, $seen);
        self::assertCount(3, array_unique($seen), 'no job repeated across pages');

        foreach ($runtime->jobs->all() as $job) {
            $runtime->jobs->cancel($job->id, 'test_done');
        }
        foreach ($futures as $future) {
            try {
                $future->await();
            } catch (\Throwable) {
                // cancelled jobs surface CancelledException; irrelevant here.
            }
        }
        $client->close();
        $serverFuture->await();
    }

    public function testModelUseEnforcementRejectsModelOutsideLease(): void
    {
        // §9.7: an agent attempt to use a model outside the lease's
        // model.use patterns surfaces PERMISSION_DENIED.
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('modeler', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->assertModelAllowed('openai/gpt-4o');
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities(features: ['model.use']));

        $caught = null;
        try {
            $client->invokeTool('modeler', ['lease' => ['model.use' => ['anthropic/*']]]);
            self::fail('expected PermissionDeniedException');
        } catch (PermissionDeniedException $e) {
            $caught = $e;
        } finally {
            $client->close();
            $serverFuture->await();
        }
        self::assertInstanceOf(PermissionDeniedException::class, $caught);
    }

    public function testMalformedCursorIsTreatedAsStart(): void
    {
        // #114: an undecodable cursor must not throw — pagination restarts
        // from the beginning (decodeCursor returns null → offset 0).
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('slow', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                delay(0.4, cancellation: $cancellation);
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities(features: ['list_jobs']));

        $future = async(fn () => $client->invokeTool('slow'));
        $deadline = microtime(true) + 2.0;
        while ($runtime->jobs->count() === 0 && microtime(true) < $deadline) {
            delay(0.01);
        }

        $page = $client->listJobs(['agent' => 'slow'], limit: 5, cursor: 'not-base64!!@@');
        self::assertCount(1, $page->jobs);

        foreach ($runtime->jobs->all() as $job) {
            $runtime->jobs->cancel($job->id, 'test_done');
        }
        try {
            $future->await();
        } catch (\Throwable) {
        }
        $client->close();
        $serverFuture->await();
    }

    /** @param array<string, mixed> $constraints */
    private function submitWithLeaseConstraints(array $constraints): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('echo', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        try {
            $client->invokeTool('echo', ['lease' => [
                'cost.budget' => ['USD:1.00'],
                'lease_constraints' => $constraints,
            ]]);
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }
}
