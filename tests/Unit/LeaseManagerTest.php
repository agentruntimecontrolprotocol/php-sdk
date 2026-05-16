<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Clock\FakeClock;
use Arcp\Errors\LeaseExpiredException;
use Arcp\Errors\LeaseRevokedException;
use Arcp\Errors\NotFoundException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Ids\LeaseId;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Runtime\LeaseManager;
use Arcp\Runtime\LeaseScope;
use PHPUnit\Framework\TestCase;

final class LeaseManagerTest extends TestCase
{
    private function makeLease(FakeClock $clock, string $id = 'lease_x'): LeaseGranted
    {
        return new LeaseGranted(
            new LeaseId($id),
            'permission.write',
            'resource:1',
            'op',
            $clock->now()->modify('+5 minutes'),
        );
    }

    public function testRegisterAndGet(): void
    {
        $clock = new FakeClock();
        $mgr = new LeaseManager($clock);
        $lease = $this->makeLease($clock);
        $mgr->register($lease);
        self::assertSame($lease, $mgr->get($lease->leaseId));
    }

    public function testGetUnknownThrowsNotFound(): void
    {
        $mgr = new LeaseManager(new FakeClock());
        $this->expectException(NotFoundException::class);
        $mgr->get(new LeaseId('lease_missing'));
    }

    public function testExpiredLeaseRaisesExpiredException(): void
    {
        $clock = new FakeClock();
        $mgr = new LeaseManager($clock);
        $lease = $this->makeLease($clock);
        $mgr->register($lease);
        $clock->advance(600);
        $this->expectException(LeaseExpiredException::class);
        $mgr->get($lease->leaseId);
    }

    public function testRevokedLeaseRaisesRevokedException(): void
    {
        $clock = new FakeClock();
        $mgr = new LeaseManager($clock);
        $lease = $this->makeLease($clock);
        $mgr->register($lease);
        $mgr->revoke($lease->leaseId, 'policy');
        self::assertTrue($mgr->isRevoked($lease->leaseId));
        $this->expectException(LeaseRevokedException::class);
        $mgr->get($lease->leaseId);
    }

    public function testEnsureUsableValidatesScope(): void
    {
        $clock = new FakeClock();
        $mgr = new LeaseManager($clock);
        $lease = $this->makeLease($clock);
        $mgr->register($lease);
        $this->expectException(PermissionDeniedException::class);
        $mgr->ensureUsable(
            $lease->leaseId,
            new LeaseScope('permission.write', 'resource:DIFFERENT', 'op'),
        );
    }

    public function testExtendUpdatesExpiry(): void
    {
        $clock = new FakeClock();
        $mgr = new LeaseManager($clock);
        $lease = $this->makeLease($clock);
        $mgr->register($lease);
        $newExp = $clock->now()->modify('+1 hour');
        $extended = $mgr->extend($lease->leaseId, $newExp);
        self::assertEquals($newExp, $extended->expiresAt);
    }

    public function testAllReturnsCurrentLeases(): void
    {
        $clock = new FakeClock();
        $mgr = new LeaseManager($clock);
        $a = $this->makeLease($clock, 'lease_a');
        $b = $this->makeLease($clock, 'lease_b');
        $mgr->register($a);
        $mgr->register($b);
        self::assertCount(2, $mgr->all());
    }
}
