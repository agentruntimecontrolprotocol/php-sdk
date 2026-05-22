<?php

declare(strict_types=1);

/**
 * @param list<string> $questions
 *
 * @return list<array{sub_question: string, lease_usd: float}>
 */
function allocateBudget(array $questions, float $totalUsd): array
{
    $slice = floor(($totalUsd / max(1, count($questions))) * 100) / 100;

    return array_map(
        static fn (string $question): array => ['sub_question' => $question, 'lease_usd' => $slice],
        $questions,
    );
}

print json_encode(allocateBudget(['sdk', 'runtime', 'ops'], 3.00), JSON_THROW_ON_ERROR) . "\n";
