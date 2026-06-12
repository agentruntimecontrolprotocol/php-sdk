<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Arcp\Errors\InvalidArgumentException;
use Arcp\Runtime\CostBudget;
use PHPUnit\Framework\TestCase;

final class CostBudgetTest extends TestCase
{
    public function testConsumeIgnoresNonCostMetrics(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.00']);
        self::assertNull($budget->consume('latency.ms', 10, 'USD'));
        self::assertSame(['USD' => '1'], $budget->remaining());
    }

    public function testConsumeIgnoresCostBudgetRemaining(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.00']);
        self::assertNull($budget->consume('cost.budget.remaining', 10, 'USD'));
        self::assertSame(['USD' => '1'], $budget->remaining());
    }

    public function testMultipleCurrenciesIndependent(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.00', 'tokens:1000']);
        self::assertSame('900', $budget->consume('cost.tokens', 100, 'tokens'));
        self::assertSame(['USD' => '1', 'tokens' => '900'], $budget->remaining());
    }

    public function testParseRejectsBadPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CostBudget::fromPatterns(['USD']);
    }

    public function testConsumeAcceptsSmallFloatCost(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.000000']);
        // 0.000001 stringifies as "1.0E-6" in PHP; must still consume.
        self::assertSame('0.999999', $budget->consume('cost.usd', 0.000001, 'USD'));
    }

    public function testConsumeAcceptsScientificStringificationOfFloat(): void
    {
        // Sanity check on PHP's stringification of small floats; this is
        // what regressed in the bug report.
        self::assertSame('1.0E-6', (string) 0.000001);
        $budget = CostBudget::fromPatterns(['USD:0.000010']);
        self::assertSame('0.000009', $budget->consume('cost.usd', 0.000001, 'USD'));
    }

    public function testConsumeRejectsInfiniteFloat(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.00']);
        $this->expectException(InvalidArgumentException::class);
        $budget->consume('cost.usd', \INF, 'USD');
    }

    public function testConsumeRejectsNanFloat(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.00']);
        $this->expectException(InvalidArgumentException::class);
        $budget->consume('cost.usd', \NAN, 'USD');
    }

    public function testPatternRejectsOverPrecision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('six-place');
        CostBudget::fromPatterns(['USD:0.0000009']);
    }

    public function testPatternAcceptsExactlySixDecimals(): void
    {
        $budget = CostBudget::fromPatterns(['USD:0.000001']);
        self::assertSame(['USD' => '0.000001'], $budget->remaining());
    }

    public function testConsumeRejectsNegativeValue(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.00']);
        try {
            $budget->consume('cost.refund', -1, 'USD');
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // §9.6: negative values are rejected and produce no decrement.
        }
        self::assertSame(['USD' => '1'], $budget->remaining());
    }

    public function testConsumingExactRemainingReturnsZeroWithoutExhausting(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.00']);
        self::assertSame('0', $budget->consume('cost.inference', 1.00, 'USD'));
    }

    public function testConsumingBeyondRemainingExhausts(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.00']);
        $this->expectException(\Arcp\Errors\BudgetExhaustedException::class);
        $budget->consume('cost.inference', 1.01, 'USD');
    }
}
