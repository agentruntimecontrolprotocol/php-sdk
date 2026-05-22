<?php

declare(strict_types=1);

/**
 * @param array{query: string} $request
 *
 * @return array{content: list<array{type: string, text: string}>}
 */
function callResearchTool(array $request): array
{
    $jobId = 'job_' . substr(hash('sha256', $request['query']), 0, 12);

    return [
        'content' => [
            ['type' => 'text', 'text' => 'submitted ARCP job ' . $jobId],
        ],
    ];
}

print json_encode(callResearchTool(['query' => 'summarize budget risks']), JSON_THROW_ON_ERROR) . "\n";
