<?php

declare(strict_types=1);

/*
 * 06 — Relay HITL scenario (RFC §12.3 first-response wins).
 *
 * One tool issues a `human.choice.request`; the client implements a
 * "relay" handler that imagines fanning out to multiple destinations
 * and picks the first response. We simulate the relay by selecting a
 * deterministic answer here, but the structure mirrors a real
 * production relay (phone/email/dashboard).
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
$runtime->registerTool('handle_failed_tests', new class () implements ToolHandler {
    #[\Override]
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        $resp = $ctx->requestHumanChoice(
            'Three test files failed. How should I proceed?',
            [
                ['id' => 'fix', 'label' => 'Fix and re-run'],
                ['id' => 'skip', 'label' => 'Skip and continue'],
                ['id' => 'abort', 'label' => 'Abort the job'],
            ],
            new \DateTimeImmutable('+5 minutes'),
        );
        return ['choice' => $resp->choiceId, 'responded_by' => $resp->respondedBy];
    }
});

[$serverT, $clientT] = MemoryTransport::pair();
$serverFuture = $runtime->serveAsync($serverT);

$relay = new CallbackHumanInputHandler(
    onInput: fn (HumanInputRequest $r) => new HumanInputResponse(null),
    onChoice: function (HumanChoiceRequest $r): HumanChoiceResponse {
        // Imagine fanning out to ntfy + email + Slack. First response wins.
        $first = $r->options !== [] ? $r->options[0]['id'] : 'abort';
        return new HumanChoiceResponse($first, 'relay:slack', new \DateTimeImmutable());
    },
);

$client = new ARCPClient($clientT, humanInputHandler: $relay);
$client->open(Auth::none(), new PeerInfo('arcp-sample', '0.1'), new Capabilities(humanInput: true, anonymous: true));

$result = $client->invokeTool('handle_failed_tests');
printf("Decision: %s\n", json_encode($result->value, \JSON_THROW_ON_ERROR));

$client->close();
$serverFuture->await();
