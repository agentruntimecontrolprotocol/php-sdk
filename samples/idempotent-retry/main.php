<?php

declare(strict_types=1);

/**
 * @param array<string, array{agent: string, job_id: string}> $records
 *
 * @return array{0: string, 1: array<string, array{agent: string, job_id: string}>}
 */
function submitIdempotent(array $records, string $principal, string $key, string $agent): array
{
    $recordKey = $principal . ':' . $key;
    if (isset($records[$recordKey])) {
        if ($records[$recordKey]['agent'] !== $agent) {
            throw new RuntimeException('DUPLICATE_KEY');
        }

        return [$records[$recordKey]['job_id'], $records];
    }

    $records[$recordKey] = ['agent' => $agent, 'job_id' => 'job_' . substr(hash('sha256', $recordKey), 0, 12)];

    return [$records[$recordKey]['job_id'], $records];
}

$records = [];
[$first, $records] = submitIdempotent($records, 'user:demo', 'retry-42', 'planner@1.0.0');
[$second] = submitIdempotent($records, 'user:demo', 'retry-42', 'planner@1.0.0');

printf("%s %s\n", $first, $second);
