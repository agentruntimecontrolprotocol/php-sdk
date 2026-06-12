<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime\Credentials;

use Arcp\Ids\JobId;
use Arcp\Ids\LeaseId;
use Arcp\Ids\SessionId;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\Credentials\Credential;
use Arcp\Runtime\Credentials\InMemoryCredentialProvisioner;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\Session;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class InMemoryCredentialProvisionerTest extends TestCase
{
    private function context(): JobContext
    {
        $runtime = new ARCPRuntime();
        [$transport] = MemoryTransport::pair();
        $session = new Session($transport, isClient: false);
        return new JobContext($runtime, $session, JobId::random(), SessionId::random());
    }

    private function lease(): LeaseGranted
    {
        return new LeaseGranted(
            LeaseId::random(),
            'tool.invoke',
            'planner',
            'run',
            new \DateTimeImmutable('+5 minutes'),
        );
    }

    public function testSeededCredentialsMintDistinctValuesPerJob(): void
    {
        $provisioner = new InMemoryCredentialProvisioner([
            new Credential('cred', 'bearer', 'token', 'https://api.example'),
        ]);
        $ctx = $this->context();

        $jobA = $provisioner->issue($this->lease(), $ctx);
        $jobB = $provisioner->issue($this->lease(), $ctx);

        self::assertNotSame($jobA[0]->id, $jobB[0]->id, 'ids must be unique per job');
        self::assertNotSame($jobA[0]->value, $jobB[0]->value, 'values must not be reused across jobs');
    }

    public function testSeededCredentialConstraintsReflectIssuingLease(): void
    {
        $provisioner = new InMemoryCredentialProvisioner([
            new Credential('cred', 'bearer', 'token', 'https://api.example'),
        ]);
        $lease = $this->lease();
        $issued = $provisioner->issue($lease, $this->context());

        $constraints = $issued[0]->constraints ?? [];
        $leaseConstraints = $constraints['lease_constraints'] ?? null;
        self::assertIsArray($leaseConstraints);
        self::assertSame(
            $lease->expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            $leaseConstraints['expires_at'] ?? null,
        );
    }

    public function testFreshMintPathUnchanged(): void
    {
        $provisioner = new InMemoryCredentialProvisioner();
        $issued = $provisioner->issue($this->lease(), $this->context());

        self::assertSame('cred_1', $issued[0]->id);
        self::assertSame('token_1', $issued[0]->value);
    }
}
