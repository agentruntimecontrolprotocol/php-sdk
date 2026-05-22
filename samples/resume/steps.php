<?php

declare(strict_types=1);

namespace Arcp\Samples\Resumability;

use Arcp\Client\ARCPClient;

/**
 * Step bodies. Real version: a workflow node per step (Anthropic call
 * for plan / synth / critique / finalize, vector retriever for gather).
 *
 * @param array<string, mixed> $inputs
 */
function runStep(ARCPClient $client, string $jobId, string $step, array $inputs): mixed
{
    throw new \RuntimeException('not implemented');
}
