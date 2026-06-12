<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use function Amp\async;

use Amp\Cancellation;

use function Amp\delay;

use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Errors\CancelledException;
use Arcp\Errors\InvalidRequestException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobEvent;
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
use Arcp\Transport\Transport;
use PHPUnit\Framework\TestCase;

final class CredentialLifecycleTest extends TestCase
{
    public function testIssueCredentialsOnJobAcceptedAndRevokeOnSuccess(): void
    {
        $provisioner = new InMemoryCredentialProvisioner();
        [$runtime, $client, $recording, $serverFuture] = $this->runtimeClient($provisioner);
        $runtime->registerTool('planner', $this->handler(
            fn (array $arguments, JobContext $ctx, ?Cancellation $cancellation): array => ['ok' => true],
        ));

        self::assertSame(['ok' => true], $client->invokeTool('planner', $this->leaseArguments())->result);

        $accepted = $recording->firstPayload(JobAccepted::class);
        self::assertInstanceOf(JobAccepted::class, $accepted);
        self::assertSame('token_1', $accepted->credentials[0]['value'] ?? null);
        self::assertSame(['cred_1'], $provisioner->issued);
        self::assertSame(['cred_1'], $provisioner->revoked);
        self::assertSame([], $runtime->credentials->outstanding());

        $client->close();
        $serverFuture->await();
    }

    public function testRevokeOnFailure(): void
    {
        $provisioner = new InMemoryCredentialProvisioner();
        [$runtime, $client, , $serverFuture] = $this->runtimeClient($provisioner);
        $runtime->registerTool('boom', $this->handler(
            fn (array $arguments, JobContext $ctx, ?Cancellation $cancellation): never
                => throw new InvalidRequestException('bad input'),
        ));

        try {
            $client->invokeTool('boom', $this->leaseArguments());
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException) {
            self::assertSame(['cred_1'], $provisioner->revoked);
            self::assertSame([], $runtime->credentials->outstanding());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testRevokeOnCancel(): void
    {
        $provisioner = new InMemoryCredentialProvisioner();
        [$runtime, $client, , $serverFuture] = $this->runtimeClient($provisioner);
        $runtime->registerTool('slow', $this->handler(function (
            array $arguments,
            JobContext $ctx,
            ?Cancellation $cancellation,
        ): array {
            delay(5, cancellation: $cancellation);
            return ['ok' => true];
        }));

        $future = async(fn () => $client->invokeTool('slow', $this->leaseArguments()));
        $deadline = microtime(true) + 2.0;
        while ($runtime->jobs->count() === 0 && microtime(true) < $deadline) {
            delay(0.01);
        }
        $job = $runtime->jobs->all()[0] ?? null;
        self::assertNotNull($job);
        $client->cancelJob($job->id);

        try {
            $future->await();
            self::fail('expected cancellation');
        } catch (CancelledException) {
            self::assertSame(['cred_1'], $provisioner->revoked);
            self::assertSame([], $runtime->credentials->outstanding());
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testCredentialRotationEmitsStatusAndRevokesPriorValue(): void
    {
        $provisioner = new InMemoryCredentialProvisioner();
        [$runtime, $client, $recording, $serverFuture] = $this->runtimeClient($provisioner);
        $runtime->registerTool('rotate', $this->handler(function (
            array $arguments,
            JobContext $ctx,
            ?Cancellation $cancellation,
        ): array {
            $ctx->rotateCredential(new Credential('cred_2', 'bearer', 'rotated', 'memory://credentials'), 'cred_1');
            return ['ok' => true];
        }));

        self::assertSame(['ok' => true], $client->invokeTool('rotate', $this->leaseArguments())->result);

        $status = $recording->firstPayload(JobEvent::class, fn (JobEvent $event): bool => $event->eventKind === 'status' && ($event->body['phase'] ?? null) === 'credential_rotated');
        self::assertInstanceOf(JobEvent::class, $status);
        self::assertSame('credential_rotated', $status->body['phase'] ?? null);
        self::assertSame('rotated', $status->body['value'] ?? null);
        self::assertContains('cred_1', $provisioner->revoked);
        self::assertContains('cred_2', $provisioner->revoked);

        $client->close();
        $serverFuture->await();
    }

    public function testModelUseMissFailsWithPermissionDenied(): void
    {
        $provisioner = new InMemoryCredentialProvisioner();
        [$runtime, $client, , $serverFuture] = $this->runtimeClient($provisioner);
        $runtime->registerTool('llm', $this->handler(function (
            array $arguments,
            JobContext $ctx,
            ?Cancellation $cancellation,
        ): array {
            $ctx->assertModelAllowed('openai/gpt-4o');
            return ['ok' => true];
        }));

        try {
            $client->invokeTool('llm', ['lease' => ['model.use' => ['anthropic/*']]]);
            self::fail('expected PermissionDeniedException');
        } catch (PermissionDeniedException) {
            self::assertSame(['cred_1'], $provisioner->revoked);
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testSubscribersSeeRedactedAcceptedCredentials(): void
    {
        $provisioner = new InMemoryCredentialProvisioner();
        [$runtime, $client, , $serverFuture] = $this->runtimeClient($provisioner);
        $runtime->registerTool('planner', $this->handler(
            fn (array $arguments, JobContext $ctx, ?Cancellation $cancellation): array => ['ok' => true],
        ));
        $client->invokeTool('planner', $this->leaseArguments());
        $jobs = $runtime->jobs->all();
        self::assertNotSame([], $jobs);

        // Attach with history replay (§7.6): the redacted job.accepted is
        // replayed from the event log to the subscriber.
        $seen = [];
        $client->subscribe($jobs[0]->id, function (Envelope $env) use (&$seen): void {
            $seen[] = $env;
        }, history: true);
        \Amp\delay(0.05);

        self::assertNotEmpty($seen);
        $payload = null;
        foreach ($seen as $env) {
            if ($env->payload instanceof JobAccepted) {
                $payload = $env->payload;
                break;
            }
        }
        self::assertInstanceOf(JobAccepted::class, $payload);
        self::assertSame('***', $payload->credentials[0]['value'] ?? null);

        $client->close();
        $serverFuture->await();
    }

    public function testRuntimeRejectsInMemoryCredentialStoreWithProvisioner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('durable revocation');
        new ARCPRuntime(
            authRouter: new AuthRouter([new AnonymousAuth()]),
            credentialProvisioner: new InMemoryCredentialProvisioner(),
            // No credentialStore given → default InMemoryCredentialStore,
            // which now (correctly) reports no durable revocation.
        );
    }

    /**
     * @return array{0: ARCPRuntime, 1: ARCPClient, 2: RecordingTransport, 3: \Amp\Future<mixed>}
     */
    private function runtimeClient(InMemoryCredentialProvisioner $provisioner): array
    {
        $runtime = new ARCPRuntime(
            authRouter: new AuthRouter([new AnonymousAuth()]),
            credentialProvisioner: $provisioner,
            credentialStore: new FakeDurableCredentialStore(),
        );
        [$serverT, $clientT] = MemoryTransport::pair();
        $recording = new RecordingTransport($clientT);
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($recording);
        $accepted = $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities(
            features: ['provisioned_credentials', 'model.use', 'subscribe'],
        ));
        // §6.2 intersection preserves the runtime's advertised order.
        self::assertSame(
            ['subscribe', 'provisioned_credentials', 'model.use'],
            $accepted->capabilities->features,
        );
        return [$runtime, $client, $recording, $serverFuture];
    }

    /** @return array<string, mixed> */
    private function leaseArguments(): array
    {
        return ['lease' => ['model.use' => ['anthropic/*'], 'cost.budget' => ['USD:1.00']]];
    }

    /**
     * @param \Closure(array<string, mixed>, JobContext, ?Cancellation): mixed $fn
     */
    private function handler(\Closure $fn): ToolHandler
    {
        return new class ($fn) implements ToolHandler {
            /** @param \Closure(array<string, mixed>, JobContext, ?Cancellation): mixed $fn */
            public function __construct(private readonly \Closure $fn)
            {
            }

            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                return ($this->fn)($arguments, $ctx, $cancellation);
            }
        };
    }
}

final class RecordingTransport implements Transport
{
    /** @var list<Envelope> */
    public array $received = [];

    public function __construct(private readonly Transport $inner)
    {
    }

    #[\Override]
    public function send(Envelope $env, ?Cancellation $cancellation = null): void
    {
        $this->inner->send($env, $cancellation);
    }

    #[\Override]
    public function receive(?Cancellation $cancellation = null): ?Envelope
    {
        $env = $this->inner->receive($cancellation);
        if ($env instanceof Envelope) {
            $this->received[] = $env;
        }
        return $env;
    }

    #[\Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->inner->isClosed();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param \Closure(T): bool|null $filter
     *
     * @return T|null
     */
    public function firstPayload(string $class, ?\Closure $filter = null): ?object
    {
        foreach ($this->received as $env) {
            $payload = $env->payload;
            if ($payload instanceof $class && ($filter === null || $filter($payload))) {
                return $payload;
            }
        }
        return null;
    }
}
