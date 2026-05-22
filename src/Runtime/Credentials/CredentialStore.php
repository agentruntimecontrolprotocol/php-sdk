<?php

declare(strict_types=1);

namespace Arcp\Runtime\Credentials;

use Arcp\Ids\JobId;

interface CredentialStore
{
    public function add(JobId $jobId, Credential $cred): void;

    public function remove(JobId $jobId, string $credentialId): void;

    /**
     * @return list<Credential>
     */
    public function forJob(JobId $jobId): array;

    /**
     * @return list<array{job_id: string, credential_id: string}>
     */
    public function outstanding(): array;

    public function supportsDurableRevocation(): bool;
}
