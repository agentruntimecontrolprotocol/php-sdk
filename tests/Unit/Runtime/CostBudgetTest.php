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
}
