<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Errors\InvalidArgumentException;

/** ARCP v1.1 §9.7 model allow-list lease capability. */
final readonly class ModelUse
{
    /** @param list<string> $patterns */
    public function __construct(public array $patterns)
    {
        foreach ($patterns as $pattern) {
            if (!$this->validPattern($pattern)) {
                throw new InvalidArgumentException('invalid model.use pattern: ' . $pattern);
            }
        }
    }

    /** @param list<string> $patterns */
    public static function fromPatterns(array $patterns): self
    {
        return new self($patterns);
    }

    /** @param array<string, mixed> $arguments */
    public static function fromInvocationArguments(array $arguments): ?self
    {
        $patterns = $arguments['model.use'] ?? null;
        $lease = $arguments['lease'] ?? null;
        if ($patterns === null && \is_array($lease)) {
            $patterns = $lease['model.use'] ?? null;
        }
        if (!\is_array($patterns)) {
            return null;
        }
        $out = [];
        foreach ($patterns as $pattern) {
            if (!\is_string($pattern)) {
                throw new InvalidArgumentException('model.use entries must be strings');
            }
            $out[] = $pattern;
        }
        return self::fromPatterns($out);
    }

    /**
     * The configured allow-list patterns. Mirrors {@see CostBudget::patterns()}
     * so lease serialization can use one uniform accessor for both.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    public function allows(string $modelId): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($this->covers($pattern, $modelId)) {
                return true;
            }
        }
        return false;
    }

    public function containsSubset(self $child): bool
    {
        foreach ($child->patterns as $childPattern) {
            $covered = false;
            foreach ($this->patterns as $parentPattern) {
                if ($this->patternCovers($parentPattern, $childPattern)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }
        return true;
    }

    private function patternCovers(string $parent, string $child): bool
    {
        if ($parent === '*') {
            return true;
        }
        if (!$this->isGlob($parent)) {
            return $parent === $child;
        }
        $prefix = substr($parent, 0, -1);
        if ($child === '*') {
            return false;
        }
        if ($this->isGlob($child)) {
            return str_starts_with(substr($child, 0, -1), $prefix);
        }
        return str_starts_with($child, $prefix);
    }

    private function covers(string $pattern, string $modelId): bool
    {
        if ($pattern === '*') {
            return true;
        }
        if ($this->isGlob($pattern)) {
            return str_starts_with($modelId, substr($pattern, 0, -1));
        }
        return $pattern === $modelId;
    }

    private function isGlob(string $pattern): bool
    {
        return str_ends_with($pattern, '*');
    }

    private function validPattern(string $pattern): bool
    {
        return $pattern === '*'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)*(?:[A-Za-z0-9._-]\*|\/\*)?$/', $pattern) === 1;
    }
}
