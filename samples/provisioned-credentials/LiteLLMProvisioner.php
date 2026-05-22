<?php

declare(strict_types=1);

namespace Arcp\Samples\ProvisionedCredentials;

use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Runtime\Credentials\Credential;
use Arcp\Runtime\Credentials\CredentialProvisioner;
use Arcp\Runtime\JobContext;

/**
 * Reference plug-in sketch for LiteLLM proxy virtual keys.
 *
 * A production implementation would POST to `/key/generate` with
 * max_budget, allowed_models, and expires, then POST `/key/delete` in
 * revoke(). This sample keeps the HTTP client out of core.
 */
final readonly class LiteLLMProvisioner implements CredentialProvisioner
{
    public function __construct(
        private string $endpoint,
        private string $adminToken,
    ) {
    }

    #[\Override]
    public function issue(LeaseGranted $lease, JobContext $ctx): array
    {
        $id = 'litellm_' . substr(hash('sha256', (string) $ctx->jobId), 0, 12);
        return [
            Credential::withLeaseConstraints(
                $id,
                'replace-with-virtual-key-from-' . $this->adminToken,
                rtrim($this->endpoint, '/') . '/v1',
                $lease,
                profile: 'litellm',
            ),
        ];
    }

    #[\Override]
    public function revoke(string $credentialId): void
    {
        // POST /key/delete with the virtual key id.
    }
}
