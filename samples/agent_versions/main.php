<?php

declare(strict_types=1);

use Amp\Cancellation;
use Arcp\Client\ARCPClient;
use Arcp\Errors\AgentVersionNotAvailableException;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;

require __DIR__ . '/../../vendor/autoload.php';

function elidedClient(): ARCPClient
{
    throw new RuntimeException('client setup elided');
}

$runtime = new ARCPRuntime();
foreach (['1.0.0', '2.0.0'] as $version) {
    $runtime->registerToolVersion('planner', $version, new class ($version) implements ToolHandler {
        public function __construct(private readonly string $version)
        {
        }

        public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
        {
            return ['planner_version' => $this->version];
        }
    });
}
$runtime->setDefaultToolVersion('planner', '2.0.0');

$client = elidedClient();

$client->invokeTool('planner');
$client->invokeTool('planner@1.0.0');

try {
    $client->invokeTool('planner@9.9.9');
} catch (AgentVersionNotAvailableException $e) {
    printf("%s\n", $e->code()->value);
}
