<?php

declare(strict_types=1);

namespace Arcp\Samples\Handoff;

/**
 * Cheap-tier inference. Real version: an Anthropic / LiteLLM call with a
 * system prompt asking for a `Confidence: X.XX` line, then heuristics on
 * top to derive the final score.
 *
 * @return array{0: string, 1: float}
 */
function attempt(string $prompt): array
{
    throw new \RuntimeException('not implemented');
}
