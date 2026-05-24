<?php

declare(strict_types=1);

/**
 * @return list<array{type: string, payload: array<string, mixed>}>
 */
function streamJobEvents(): array
{
    return [
        ['type' => 'job.started', 'payload' => ['phase' => 'start']],
        ['type' => 'log', 'payload' => ['level' => 'info', 'message' => 'planning']],
        ['type' => 'thought', 'payload' => ['text' => 'choose tool']],
        ['type' => 'metric', 'payload' => ['name' => 'cost.usd', 'value' => 0.03, 'unit' => 'USD']],
        ['type' => 'tool.result', 'payload' => ['tool' => 'search', 'ok' => true]],
        ['type' => 'artifact.ref', 'payload' => ['uri' => 'art_report']],
        ['type' => 'job.completed', 'payload' => ['value' => 'done']],
    ];
}

foreach (streamJobEvents() as $event) {
    printf("%s\n", $event['type']);
}
