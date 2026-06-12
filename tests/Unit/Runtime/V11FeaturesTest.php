<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Arcp\Errors\BudgetExhaustedException;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Runtime\AgentRef;
use Arcp\Runtime\CostBudget;
use PHPUnit\Framework\TestCase;

final class V11FeaturesTest extends TestCase
{
    public function testAgentRefParsesNameAndVersion(): void
    {
        self::assertEquals(new AgentRef('planner'), AgentRef::parse('planner'));
        self::assertEquals(new AgentRef('planner', '1.0.0'), AgentRef::parse('planner@1.0.0'));
    }

    public function testAgentRefRejectsInvalidGrammar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgentRef::parse('Planner@bad/version');
    }

    public function testAgentRefRejectsUppercaseName(): void
    {
        // §7.5: name ::= [a-z0-9][a-z0-9._-]* (lowercase only).
        $this->expectException(InvalidArgumentException::class);
        new AgentRef('MyTool');
    }

    public function testCostBudgetConsumesCostMetrics(): void
    {
        $budget = CostBudget::fromPatterns(['USD:1.00']);
        self::assertSame('0.75', $budget->consume('cost.search', 0.25, 'USD'));
        self::assertSame(['USD' => '0.75'], $budget->remaining());
    }

    public function testCostBudgetThrowsWhenExhausted(): void
    {
        $budget = CostBudget::fromPatterns(['USD:0.10']);
        $this->expectException(BudgetExhaustedException::class);
        $budget->consume('cost.search', 0.10, 'USD');
    }

    public function testCostBudgetSubsetUsesRemainingBudget(): void
    {
        $parent = CostBudget::fromPatterns(['USD:1.00']);
        self::assertTrue($parent->containsSubset(CostBudget::fromPatterns(['USD:0.50'])));
        self::assertFalse($parent->containsSubset(CostBudget::fromPatterns(['USD:2.00'])));
    }
}
