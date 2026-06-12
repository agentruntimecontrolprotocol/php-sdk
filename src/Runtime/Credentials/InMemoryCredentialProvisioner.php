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
        $n = $this->next++;
        if ($this->credentials === []) {
            $credentials = [
                Credential::withLeaseConstraints(
                    'cred_' . $n,
                    'token_' . $n,
                    'memory://credentials',
                    $lease,
                ),
            ];
        } else {
            // §9.8.2: credentials are scoped to a single job and a value
            // MUST NOT be reused across jobs. Mint a per-job copy of each
            // seed template with a unique id/value and constraints rebuilt
            // from the issuing job's lease.
            $credentials = [];
            foreach ($this->credentials as $template) {
                $credentials[] = Credential::withLeaseConstraints(
                    $template->id . '_' . $n,
                    $template->value . '_' . $n,
                    $template->endpoint,
                    $lease,
                    $template->scheme,
                    $template->profile,
                );
            }
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
