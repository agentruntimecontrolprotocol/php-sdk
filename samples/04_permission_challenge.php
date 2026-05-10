<?php

declare(strict_types=1);

/*
 * 04 — Permission challenge + lease.
 *
 * Tool requests scoped permission for a refund operation; client
 * auto-approves with a 5-minute lease. Demonstrates RFC §15.4 and §15.5.
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Cancellation;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Client\Handlers\AutoApprovePermissionHandler;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;

$runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
$runtime->registerTool('refund', new class () implements ToolHandler {
    #[\Override]
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        $orderId = $arguments['order_id'] ?? 'unknown';
        $leaseId = $ctx->requestPermission(
            'payment.refund.create',
            'order:' . (is_string($orderId) ? $orderId : 'unknown'),
            'refund',
            'customer-approved',
            requestedLeaseSeconds: 300,
        );
        // In production we'd hit the payment API here under the lease.
        return ['refund_id' => 'rfd_' . bin2hex(random_bytes(4)), 'lease' => (string) $leaseId];
    }
});

[$serverT, $clientT] = MemoryTransport::pair();
$serverFuture = $runtime->serveAsync($serverT);

$client = new ARCPClient($clientT, permissionHandler: new AutoApprovePermissionHandler(300));
$client->open(Auth::none(), new PeerInfo('arcp-sample', '0.1'), new Capabilities(anonymous: true));

$result = $client->invokeTool('refund', ['order_id' => 'ord_4812']);
printf("Refund result: %s\n", json_encode($result->value, \JSON_THROW_ON_ERROR));

$client->close();
$serverFuture->await();
