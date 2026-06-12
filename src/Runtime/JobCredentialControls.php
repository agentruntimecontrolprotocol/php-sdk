<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Errors\InvalidRequestException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Runtime\Credentials\Credential;
use Arcp\Runtime\Credentials\CredentialProvisioner;

trait JobCredentialControls
{
    public function assertModelAllowed(string $modelId): void
    {
        $job = $this->runtime->jobs->tryGet($this->jobId);
        $modelUse = $job?->lease?->modelUse;
        if ($modelUse !== null && !$modelUse->allows($modelId)) {
            throw new PermissionDeniedException('model.use', $modelId, 'model not allowed by lease');
        }
    }

    public function rotateCredential(Credential $new, string $previousCredentialId): void
    {
        $provisioner = $this->runtime->credentialProvisioner;
        if (!$provisioner instanceof CredentialProvisioner) {
            throw new InvalidRequestException('no credential provisioner configured');
        }
        // Add the replacement before removing the old record so a store
        // add() failure cannot leave the job with neither credential.
        $this->runtime->credentials->add($this->jobId, $new);
        $this->runtime->credentials->remove($this->jobId, $previousCredentialId);
        $this->revokePreviousCredential($provisioner, $previousCredentialId);
        $this->runtime->emit($this->session, new EventEmit('status', [
            'phase' => 'credential_rotated',
            'id' => $new->id,
            'value' => $new->value,
        ]), [
            'job_id' => $this->jobId,
            'trace_id' => $this->traceId,
        ]);
    }

    private function revokePreviousCredential(
        CredentialProvisioner $provisioner,
        string $credentialId,
    ): void {
        // §9.8.2: revocation is best-effort but SHOULD retry transient
        // failures before giving up and logging a permanent failure.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $provisioner->revoke($credentialId);
                return;
            } catch (\Throwable $e) {
                if ($attempt < 2) {
                    \Amp\delay(0.02 * $attempt);

                    continue;
                }
                $this->runtime->logger->error(
                    'credential revocation failed during rotation',
                    ['credential_id' => $credentialId, 'error' => $e->getMessage()],
                );
            }
        }
    }
}
