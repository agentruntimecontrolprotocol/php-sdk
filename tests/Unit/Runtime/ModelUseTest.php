<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Arcp\Runtime\ModelUse;
use PHPUnit\Framework\TestCase;

final class ModelUseTest extends TestCase
{
    public function testAllowsExactMatch(): void
    {
        $models = ModelUse::fromPatterns(['openai/gpt-4o']);
        self::assertTrue($models->allows('openai/gpt-4o'));
        self::assertFalse($models->allows('openai/gpt-4o-mini'));
    }

    public function testAllowsPrefixGlob(): void
    {
        $models = ModelUse::fromPatterns(['anthropic/*']);
        self::assertTrue($models->allows('anthropic/claude-3'));
        self::assertFalse($models->allows('openai/gpt-4o'));
    }

    public function testContainsSubsetTrueForNarrowChild(): void
    {
        $parent = ModelUse::fromPatterns(['anthropic/*']);
        $child = ModelUse::fromPatterns(['anthropic/claude-3']);
        self::assertTrue($parent->containsSubset($child));
    }

    public function testContainsSubsetFalseForExpandedChild(): void
    {
        $parent = ModelUse::fromPatterns(['openai/gpt-4o']);
        $child = ModelUse::fromPatterns(['openai/*']);
        self::assertFalse($parent->containsSubset($child));
    }
}
