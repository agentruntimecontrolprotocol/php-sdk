<?php

declare(strict_types=1);

namespace Arcp\Tests\Support;

use Arcp\Ids\JobId;
use Arcp\Runtime\Credentials\Credential;
use Arcp\Runtime\Credentials\CredentialStore;

/**
 * Test double that advertises durable revocation. Used by tests that must
 * pair a CredentialProvisioner with a store but do not need to exercise
 * actual durability across process restarts.
 */
final class FakeDurableCredentialStore implements CredentialStore
{
    /** @var array<string, array<string, Credential>> */
    private array $byJob = [];

    #[\Override]
    public function add(JobId $jobId, Credential $cred): void
    {
        $this->byJob[(string) $jobId][$cred->id] = $cred;
    }

    #[\Override]
    public function remove(JobId $jobId, string $credentialId): void
    {
        unset($this->byJob[(string) $jobId][$credentialId]);
        if (($this->byJob[(string) $jobId] ?? []) === []) {
            unset($this->byJob[(string) $jobId]);
        }
    }

    #[\Override]
    public function forJob(JobId $jobId): array
    {
        return array_values($this->byJob[(string) $jobId] ?? []);
    }

    #[\Override]
    public function outstanding(): array
    {
        $out = [];
        foreach ($this->byJob as $jobId => $credentials) {
            foreach (array_keys($credentials) as $credentialId) {
                $out[] = ['job_id' => $jobId, 'credential_id' => $credentialId];
            }
        }
        return $out;
    }

    #[\Override]
    public function supportsDurableRevocation(): bool
    {
        return true;
    }
}
