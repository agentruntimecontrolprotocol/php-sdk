<?php

declare(strict_types=1);

namespace Arcp\Runtime\Credentials;

use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Runtime\JobContext;

interface CredentialProvisioner
{
    /**
     * @return list<Credential>
     */
    public function issue(LeaseGranted $lease, JobContext $ctx): array;

    public function revoke(string $credentialId): void;
}
