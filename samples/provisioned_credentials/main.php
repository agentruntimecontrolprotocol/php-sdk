<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Amp\Cancellation;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Credentials\InMemoryCredentialProvisioner;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;
use Arcp\Transport\Transport;

$provisioner = new InMemoryCredentialProvisioner();
$runtime = new ARCPRuntime(
    authRouter: new AuthRouter([new NoneAuth()]),
    credentialProvisioner: $provisioner,
);
$runtime->registerTool('planner', new class () implements ToolHandler {
    #[\Override]
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        $ctx->assertModelAllowed('anthropic/claude-3-5-sonnet');
        return ['planned' => true];
    }
});

[$serverT, $clientT] = MemoryTransport::pair();
$recording = new class ($clientT) implements Transport {
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
};
$serverFuture = $runtime->serveAsync($serverT);
$client = new ARCPClient($recording);
$client->open(Auth::none(), new PeerInfo('provisioned-demo', '0.1'), new Capabilities(
    anonymous: true,
    features: ['provisioned_credentials', 'model.use'],
));
$result = $client->invokeTool('planner', [
    'lease' => [
        'model.use' => ['anthropic/*'],
        'cost.budget' => ['USD:1.00'],
    ],
]);

foreach ($recording->received as $env) {
    if ($env->payload instanceof JobAccepted) {
        print_r($env->payload->credentials);
        break;
    }
}
print_r($result->value);
print_r(['revoked' => $provisioner->revoked]);

$client->close();
$serverFuture->await();
