<?php

declare(strict_types=1);

use Arcp\Client\ARCPClient;
use Arcp\Runtime\JobContext;

require __DIR__ . '/../../vendor/autoload.php';

/** @return array<string, string> */
function buildReport(JobContext $ctx): array
{
    $ctx->emitResultChunk('res_report', 'alpha, ');
    $ctx->emitResultChunk('res_report', 'beta', more: false);
    return ['result_id' => 'res_report'];
}

function elidedClient(): ARCPClient
{
    throw new RuntimeException('client setup elided');
}

$client = elidedClient();

$result = $client->invokeTool('reporter');
$value = $result->result;
$resultId = is_array($value) ? ($value['result_id'] ?? null) : null;
if (is_string($resultId) && $client->resultChunks->isComplete($resultId)) {
    printf("%s\n", $client->resultChunks->assemble($resultId));
}
