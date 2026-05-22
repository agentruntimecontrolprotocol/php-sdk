<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Errors\FailedPreconditionException;
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
            throw new FailedPreconditionException('no credential provisioner configured');
        }
        $this->runtime->credentials->remove($this->jobId, $previousCredentialId);
        $this->runtime->credentials->add($this->jobId, $new);
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
        try {
            $provisioner->revoke($credentialId);
        } catch (\Throwable $e) {
            $this->runtime->logger->warning(
                'credential revocation failed during rotation',
                ['credential_id' => $credentialId, 'error' => $e->getMessage()],
            );
        }
    }
}
