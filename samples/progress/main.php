<?php

declare(strict_types=1);

/**
 * @return list<array{type: string, percent: int, message: string}>
 */
function progressEvents(): array
{
    return [
        ['type' => 'job.progress', 'percent' => 10, 'message' => 'queued'],
        ['type' => 'job.progress', 'percent' => 50, 'message' => 'running'],
        ['type' => 'job.progress', 'percent' => 100, 'message' => 'complete'],
    ];
}

foreach (progressEvents() as $event) {
    printf("%3d%% %s\n", $event['percent'], $event['message']);
}
