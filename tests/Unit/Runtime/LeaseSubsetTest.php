<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Arcp\Errors\LeaseSubsetViolationException;
use Arcp\Ids\LeaseId;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Runtime\CostBudget;
use Arcp\Runtime\LeaseManager;
use Arcp\Runtime\ModelUse;
use PHPUnit\Framework\TestCase;

final class LeaseSubsetTest extends TestCase
{
    public function testModelUseSubsetEnforced(): void
    {
        $manager = new LeaseManager();
        $parent = $this->lease('lease_parent', modelUse: ModelUse::fromPatterns(['anthropic/*']));
        $child = $this->lease('lease_child', modelUse: ModelUse::fromPatterns(['openai/*']));

        $this->expectException(LeaseSubsetViolationException::class);
        $manager->ensureSubset($parent, $child);
    }

    public function testCostBudgetSubsetEnforced(): void
    {
        $manager = new LeaseManager();
        $parent = $this->lease('lease_parent', costBudget: CostBudget::fromPatterns(['USD:1.00']));
        $child = $this->lease('lease_child', costBudget: CostBudget::fromPatterns(['USD:2.00']));

        $this->expectException(LeaseSubsetViolationException::class);
        $manager->ensureSubset($parent, $child);
    }

    private function lease(
        string $id,
        ?ModelUse $modelUse = null,
        ?CostBudget $costBudget = null,
    ): LeaseGranted {
        return new LeaseGranted(
            new LeaseId($id),
            'tool.invoke',
            'planner',
            'run',
            new \DateTimeImmutable('+5 minutes'),
            $modelUse,
            $costBudget,
        );
    }
}
