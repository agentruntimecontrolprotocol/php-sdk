<?php

declare(strict_types=1);

/*
 * 03 — Human-in-the-loop input.
 *
 * Tool invokes `requestHumanInput`; client supplies the answer through
 * a CallbackHumanInputHandler. Demonstrates RFC §12.1.
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Cancellation;
use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Client\Handlers\CallbackHumanInputHandler;
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

$runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
$runtime->registerTool('rename_branch', new class () implements ToolHandler {
    #[\Override]
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        $resp = $ctx->requestHumanInput(
            'What branch should I create for this fix?',
            ['type' => 'object', 'properties' => ['branch' => ['type' => 'string', 'minLength' => 1]], 'required' => ['branch']],
            new \DateTimeImmutable('+5 minutes'),
            default: ['branch' => 'fix/auto'],
        );
        return ['created' => $resp->value];
    }
});

[$serverT, $clientT] = MemoryTransport::pair();
$serverFuture = $runtime->serveAsync($serverT);

$handler = new CallbackHumanInputHandler(
    onInput: fn (HumanInputRequest $r) => new HumanInputResponse(
        ['branch' => 'fix/jwt-validation'],
        'cli',
        new \DateTimeImmutable(),
    ),
    onChoice: fn (HumanChoiceRequest $r) => new HumanChoiceResponse('first'),
);

$client = new ARCPClient($clientT, humanInputHandler: $handler);
$client->open(Auth::none(), new PeerInfo('arcp-sample', '0.1'), new Capabilities(humanInput: true, anonymous: true));

$result = $client->invokeTool('rename_branch');
printf("Branch chosen: %s\n", json_encode($result->value, \JSON_THROW_ON_ERROR));

$client->close();
$serverFuture->await();
