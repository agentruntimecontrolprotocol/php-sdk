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
use Arcp\Messages\Permissions\PermissionDeny;
use Arcp\Messages\Permissions\PermissionRequest;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
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
        self::assertIsArray($result->value);
        $lease = $result->value['lease'] ?? null;
        self::assertIsString($lease);
        self::assertStringStartsWith('lease_', $lease);
        // Lease should now exist in the runtime's lease manager.
        self::assertCount(1, $runtime->leases->all());

        $client->close();
        $serverFuture->await();
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
