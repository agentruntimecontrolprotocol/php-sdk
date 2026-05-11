<?php

declare(strict_types=1);

namespace Arcp\Samples\ReasoningStreams;

/**
 * One reasoning step. Real version: an Anthropic call that folds the
 * critique into the prompt when present.
 *
 * @param array<string, mixed>|null $priorCritique
 */
function primaryStep(string $request, ?array $priorCritique): string
{
    throw new \RuntimeException('not implemented');
}

/**
 * Critic LLM. Returns [severity, summary, suggestion, tokensConsumed]
 * where severity ∈ {"nudge", "warn", "halt"}.
 *
 * @return array{0: string, 1: string, 2: ?string, 3: int}
 */
function critiqueThought(string $thought): array
{
    throw new \RuntimeException('not implemented');
}
