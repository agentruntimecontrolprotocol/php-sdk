<?php

declare(strict_types=1);

namespace Arcp\Samples\Leases;

/** Real version: an Anthropic tool-use loop yielding one LLMStep per turn. */
final class ToolCall
{
    /** @param list<string> $argv */
    public function __construct(
        public readonly array $argv,
        public readonly string $reason,
    ) {
    }
}

final class LLMStep
{
    public function __construct(
        public readonly string $thought,
        public readonly ?ToolCall $toolCall = null,
        public readonly ?string $final = null,
    ) {
    }
}

/**
 * @return iterable<LLMStep>
 */
function llmLoop(string $userRequest): iterable
{
    throw new \RuntimeException('not implemented');
}
