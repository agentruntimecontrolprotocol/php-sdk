<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Amp\Cancellation;
use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
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
use PHPUnit\Framework\TestCase;

final class HumanInputTest extends TestCase
{
    public function testHumanInputRoundTrips(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('ask', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $resp = $ctx->requestHumanInput(
                    'What branch?',
                    ['type' => 'object', 'properties' => ['branch' => ['type' => 'string']]],
                    new \DateTimeImmutable('+5 minutes'),
                );
                return ['chosen' => $resp->value];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient(
            $clientT,
            humanInputHandler: new CallbackHumanInputHandler(
                onInput: fn (HumanInputRequest $r): HumanInputResponse => new HumanInputResponse(
                    ['branch' => 'fix/jwt'],
                    'cli',
                    new \DateTimeImmutable(),
                ),
                onChoice: fn (HumanChoiceRequest $r): HumanChoiceResponse => new HumanChoiceResponse('first'),
            ),
        );
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());
        $result = $client->invokeTool('ask');
        self::assertSame(['chosen' => ['branch' => 'fix/jwt']], $result->result);

        $client->close();
        $serverFuture->await();
    }

    public function testHumanChoiceRoundTrips(): void
    {
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('pick', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $resp = $ctx->requestHumanChoice(
                    'Which?',
                    [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']],
                    new \DateTimeImmutable('+5 minutes'),
                );
                return ['chosen' => $resp->choiceId];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient(
            $clientT,
            humanInputHandler: new CallbackHumanInputHandler(
                onInput: fn (HumanInputRequest $r): HumanInputResponse => new HumanInputResponse(null),
                onChoice: fn (HumanChoiceRequest $r): HumanChoiceResponse => new HumanChoiceResponse('b', 'cli', new \DateTimeImmutable()),
            ),
        );
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());
        $result = $client->invokeTool('pick');
        self::assertSame(['chosen' => 'b'], $result->result);

        $client->close();
        $serverFuture->await();
    }

    public function testExpirationWithDefaultUsesDefault(): void
    {
        // No human input handler on the client — request will time out;
        // runtime synthesizes a default response.
        $runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth()]));
        $runtime->registerTool('ask', new class () implements ToolHandler {
            #[\Override]
            public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
            {
                $resp = $ctx->requestHumanInput(
                    'auto-fallback?',
                    ['type' => 'object'],
                    new \DateTimeImmutable('+1 second'),
                    default: ['used' => 'fallback'],
                );
                return ['chosen' => $resp->value];
            }
        });
        [$serverT, $clientT] = MemoryTransport::pair();
        $serverFuture = $runtime->serveAsync($serverT);
        $client = new ARCPClient($clientT);  // no handler
        $client->open(Auth::anonymous(), new PeerInfo('cli', '0.1'), new Capabilities());

        $result = $client->invokeTool('ask', deadlineSeconds: 5.0);
        self::assertSame(['chosen' => ['used' => 'fallback']], $result->result);

        $client->close();
        $serverFuture->await();
    }
}
