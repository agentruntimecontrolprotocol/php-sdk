<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Errors\BudgetExhaustedException;
use Arcp\Errors\InvalidArgumentException;

/** ARCP v1.1 §9.6 per-currency cost.budget counters. */
final class CostBudget
{
    private const int SCALE = 1_000_000;

    /** @var array<string, int> */
    private array $remaining;

    /** @param array<string, int> $remaining */
    private function __construct(array $remaining)
    {
        $this->remaining = $remaining;
    }

    /** @param list<string> $patterns */
    public static function fromPatterns(array $patterns): self
    {
        $remaining = [];
        foreach ($patterns as $pattern) {
            [$currency, $amount] = self::parse($pattern);
            $remaining[$currency] = ($remaining[$currency] ?? 0) + $amount;
        }
        return new self($remaining);
    }

    /** @param array<string, mixed> $arguments */
    public static function fromInvocationArguments(array $arguments): ?self
    {
        $patterns = $arguments['cost.budget'] ?? null;
        $lease = $arguments['lease'] ?? null;
        if ($patterns === null && \is_array($lease)) {
            $patterns = $lease['cost.budget'] ?? null;
        }
        if (!\is_array($patterns)) {
            return null;
        }
        $out = [];
        foreach ($patterns as $pattern) {
            if (!\is_string($pattern)) {
                throw new InvalidArgumentException('cost.budget entries must be strings');
            }
            $out[] = $pattern;
        }
        return self::fromPatterns($out);
    }

    public function consume(string $metricName, int|float $value, string $unit): ?string
    {
        if (!str_starts_with($metricName, 'cost.') || $metricName === 'cost.budget.remaining') {
            return null;
        }
        if (!isset($this->remaining[$unit]) || $value < 0) {
            return null;
        }
        $this->remaining[$unit] -= self::decimalToScaled((string) $value);
        if ($this->remaining[$unit] <= 0) {
            throw new BudgetExhaustedException($unit, $this->format($this->remaining[$unit]));
        }
        return $this->format($this->remaining[$unit]);
    }

    /** @return array<string, string> */
    public function remaining(): array
    {
        $out = [];
        foreach ($this->remaining as $currency => $amount) {
            $out[$currency] = $this->format($amount);
        }
        return $out;
    }

    /** @return list<string> */
    public function patterns(): array
    {
        $out = [];
        foreach ($this->remaining as $currency => $amount) {
            $out[] = $currency . ':' . $this->format($amount);
        }
        return $out;
    }

    public function containsSubset(self $child): bool
    {
        foreach ($child->remaining as $currency => $amount) {
            if (($this->remaining[$currency] ?? 0) < $amount) {
                return false;
            }
        }
        return true;
    }

    /** @return array{0: string, 1: int} */
    private static function parse(string $pattern): array
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):(\d+(?:\.\d+)?)$/', $pattern, $m) !== 1) {
            throw new InvalidArgumentException('invalid cost.budget amount: ' . $pattern);
        }
        return [$m[1], self::decimalToScaled($m[2])];
    }

    private static function decimalToScaled(string $decimal): int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $decimal, $m) !== 1) {
            throw new InvalidArgumentException('invalid decimal amount: ' . $decimal);
        }
        $whole = (int) $m[1] * self::SCALE;
        $fraction = substr(str_pad($m[2] ?? '', 6, '0'), 0, 6);
        return $whole + (int) $fraction;
    }

    private function format(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $abs = abs($amount);
        $whole = intdiv($abs, self::SCALE);
        $fraction = str_pad((string) ($abs % self::SCALE), 6, '0', STR_PAD_LEFT);
        $trimmed = rtrim($fraction, '0');
        return $sign . $whole . ($trimmed !== '' ? '.' . $trimmed : '');
    }
}
