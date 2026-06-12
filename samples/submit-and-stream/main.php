<?php

declare(strict_types=1);

/**
 * @return list<array{type: string, payload: array<string, mixed>}>
 */
function streamJobEvents(): array
{
    return [
        ['type' => 'job.event', 'payload' => ['kind' => 'status', 'body' => ['phase' => 'running']]],
        ['type' => 'log', 'payload' => ['level' => 'info', 'message' => 'planning']],
        ['type' => 'thought', 'payload' => ['text' => 'choose tool']],
        ['type' => 'metric', 'payload' => ['name' => 'cost.usd', 'value' => 0.03, 'unit' => 'USD']],
        ['type' => 'job.event', 'payload' => ['kind' => 'tool_result', 'body' => ['call_id' => 'c1', 'result' => ['ok' => true]]]],
        ['type' => 'artifact.ref', 'payload' => ['uri' => 'art_report']],
        ['type' => 'job.result', 'payload' => ['final_status' => 'success', 'result' => 'done']],
    ];
}

foreach (streamJobEvents() as $event) {
    printf("%s\n", $event['type']);
}
