<?php

declare(strict_types=1);

/**
 * @return array{tool: string, budget_usd: float, question: string}
 */
function submitResearchPlan(string $question, float $budgetUsd): array
{
    return ['tool' => 'planner', 'budget_usd' => $budgetUsd, 'question' => $question];
}

print json_encode(submitResearchPlan('Where is ARCP useful?', 3.00), JSON_THROW_ON_ERROR) . "\n";
