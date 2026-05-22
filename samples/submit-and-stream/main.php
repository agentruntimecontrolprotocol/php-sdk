<?php

declare(strict_types=1);

/**
 * @return list<array{type: string, payload: array<string, mixed>}>
 */
function streamJobEvents(): array
{
    return [
        ['type' => 'job.started', 'payload' => ['phase' => 'start']],
        ['type' => 'log.event', 'payload' => ['level' => 'info', 'message' => 'planning']],
        ['type' => 'stream.chunk', 'payload' => ['kind' => 'thought', 'content' => 'choose tool']],
        ['type' => 'metric.event', 'payload' => ['name' => 'cost.usd', 'value' => 0.03]],
        ['type' => 'tool.result', 'payload' => ['tool' => 'search', 'ok' => true]],
        ['type' => 'artifact.ref', 'payload' => ['artifact_id' => 'art_report']],
        ['type' => 'job.completed', 'payload' => ['value' => 'done']],
    ];
}

foreach (streamJobEvents() as $event) {
    printf("%s\n", $event['type']);
}
