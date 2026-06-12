<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Amp\Cancellation;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Client\Handlers\AutoApprovePermissionHandler;
use Arcp\Client\Handlers\PermissionHandler;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Ids\LeaseId;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Messages\Permissions\PermissionDeny;
use Arcp\Messages\Permissions\PermissionRequest;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\CostBudget;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class PermissionLeaseTest extends TestCase
{
    public function testPermissionGrantedAndLeaseRegistered(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('refund', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $leaseId = $ctx->requestPermission(
                    'payment.refund.create',
                    'order:42',
                    'refund',
                    'customer-approved',
                    requestedLeaseSeconds: 60,
                );
                return ['lease' => (string) $leaseId];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient(
            $clientT,
            permissionHandler: new AutoApprovePermissionHandler(60),
        );
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));
        $result = $client->invokeTool('refund');
        self::assertIsArray($result->result);
        $lease = $result->result['lease'] ?? null;
        self::assertIsString($lease);
        self::assertStringStartsWith('lease_', $lease);
        // Lease should now exist in the runtime's lease manager.
        self::assertCount(1, $runtime->leases->all());

        $client->close();
        $serverFuture->await();
    }

    public function testRepeatedLeaseInvocationsGetIndependentBudgetCounters(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('spend', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                // Consume USD:0.40. With a per-job budget this always leaves
                // USD:0.10; if the counter were aliased across jobs the
                // second invocation would overspend and throw.
                $ctx->emitMetric('cost.usage', 0.4, 'USD');
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        $leaseId = LeaseId::random();
        $runtime->leases->register(
            new LeaseGranted(
                $leaseId,
                'tool.invoke',
                'spend',
                'run',
                new \DateTimeImmutable('+5 minutes'),
                null,
                CostBudget::fromPatterns(['USD:0.50']),
            ),
            $client->session->sessionId,
        );

        $args = ['lease' => ['lease_id' => (string) $leaseId]];
        $first = $client->invokeTool('spend', $args);
        $second = $client->invokeTool('spend', $args);

        // Both jobs start at the granted USD:0.50 and succeed independently.
        self::assertSame(['ok' => true], $first->result);
        self::assertSame(['ok' => true], $second->result);

        $client->close();
        $serverFuture->await();
    }

    public function testReferencedLeaseOverlayCannotWidenBudget(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('spend', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        $leaseId = LeaseId::random();
        $runtime->leases->register(
            new LeaseGranted(
                $leaseId,
                'tool.invoke',
                'spend',
                'run',
                new \DateTimeImmutable('+5 minutes'),
                null,
                CostBudget::fromPatterns(['USD:0.50']),
            ),
            $client->session->sessionId,
        );

        // Overlay a wider budget than the parent grants: §9.4 forbids it.
        $args = ['lease' => ['lease_id' => (string) $leaseId, 'cost.budget' => ['USD:5.00']]];
        $this->expectException(\Arcp\Errors\LeaseSubsetViolationException::class);
        try {
            $client->invokeTool('spend', $args);
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }

    public function testPermissionDenyRaisesException(): void
    {
        $denyHandler = new class () implements PermissionHandler {
            #[\Override]
            public function onPermissionRequest(PermissionRequest $req): PermissionDeny
            {
                return new PermissionDeny($req->permission, $req->resource, $req->operation, 'policy');
            }
        };
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
        $runtime->registerTool('refund', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $ctx->requestPermission('payment.refund.create', 'order:42', 'refund');
                return ['ok' => true];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT, permissionHandler: $denyHandler);
        $client->open(Auth::none(), new PeerInfo('cli', '0.1'), new Capabilities(anonymous: true));

        $this->expectException(PermissionDeniedException::class);
        try {
            $client->invokeTool('refund');
        } finally {
            $client->close();
            $serverFuture->await();
        }
    }
}
