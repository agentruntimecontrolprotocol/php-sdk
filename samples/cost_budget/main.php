<?php

declare(strict_types=1);

use Arcp\Client\ARCPClient;
use Arcp\Errors\BudgetExhaustedException;
use Arcp\Runtime\JobContext;

require __DIR__ . '/../../vendor/autoload.php';

/** @return array<string, bool> */
function spend(JobContext $ctx): array
{
    $ctx->emitMetric('cost.search', 0.60, 'USD');
    $ctx->emitMetric('cost.search', 0.40, 'USD');
    return ['ok' => true];
}

function elidedClient(): ARCPClient
{
    throw new RuntimeException('client setup elided');
}

$client = elidedClient();

try {
    $client->invokeTool('spender', ['lease' => ['cost.budget' => ['USD:1.00']]]);
} catch (BudgetExhaustedException $e) {
    printf("%s\n", $e->code()->value);
}
