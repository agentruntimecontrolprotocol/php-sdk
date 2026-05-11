<?php

declare(strict_types=1);

namespace Arcp\Samples\Heartbeats;

/**
 * Worker work. Real version: a CrewAI Crew sized per role, run via
 * crew.kickoff(inputs=...) (or, in PHP, your equivalent agent pipeline).
 *
 * @param array<string, mixed> $payload
 *
 * @return array<string, mixed>
 */
function doWork(array $payload): array
{
    throw new \RuntimeException('not implemented');
}
