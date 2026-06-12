<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Arcp\Errors\InvalidRequestException;
use Arcp\Runtime\ModelUse;
use PHPUnit\Framework\TestCase;

final class ModelUseTest extends TestCase
{
    public function testAcceptsSpecExampleSuffixGlobs(): void
    {
        // §9.7 normative example patterns plus a bare suffix glob.
        $models = ModelUse::fromPatterns([
            'tier-fast/*',
            'anthropic/claude-3-haiku-*',
            'claude-*',
        ]);
        self::assertTrue($models->allows('anthropic/claude-3-haiku-20240307'));
        self::assertTrue($models->allows('claude-instant'));
        self::assertFalse($models->allows('openai/gpt-4o'));
    }

    /**
     * @param non-empty-string $pattern
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bogusPatterns')]
    public function testRejectsBogusPatterns(string $pattern): void
    {
        $this->expectException(InvalidRequestException::class);
        ModelUse::fromPatterns([$pattern]);
    }

    /** @return iterable<string, array{0: non-empty-string}> */
    public static function bogusPatterns(): iterable
    {
        yield 'double-star' => ['**'];
        yield 'mid-star' => ['*mid*'];
        yield 'leading-star' => ['*foo'];
    }

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
