<?php

declare(strict_types=1);

namespace Arcp\Runtime\Credentials;

use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Runtime\JobContext;

final class InMemoryCredentialProvisioner implements CredentialProvisioner
{
    /** @var list<string> */
    public array $issued = [];

    /** @var list<string> */
    public array $revoked = [];

    private int $next = 1;

    /**
     * @param list<Credential> $credentials
     */
    public function __construct(private array $credentials = [])
    {
    }

    #[\Override]
    public function issue(LeaseGranted $lease, JobContext $ctx): array
    {
        $credentials = $this->credentials;
        if ($credentials === []) {
            $n = $this->next++;
            $credentials = [
                Credential::withLeaseConstraints(
                    'cred_' . $n,
                    'token_' . $n,
                    'memory://credentials',
                    $lease,
                ),
            ];
        }
        foreach ($credentials as $credential) {
            $this->issued[] = $credential->id;
        }
        return $credentials;
    }

    #[\Override]
    public function revoke(string $credentialId): void
    {
        $this->revoked[] = $credentialId;
    }
}
