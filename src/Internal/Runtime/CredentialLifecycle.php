<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorPayload;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\LeaseId;
use Arcp\Messages\Execution\ToolError;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\CostBudget;
use Arcp\Runtime\Credentials\Credential;
use Arcp\Runtime\Credentials\CredentialProvisioner;
use Arcp\Runtime\Job;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\JobState;
use Arcp\Runtime\ModelUse;
use Arcp\Runtime\ResolvedTool;
use Arcp\Runtime\Session;

/** @internal */
final readonly class CredentialLifecycle
{
    public function __construct(private ARCPRuntime $runtime)
    {
    }

    /** @param array<string, mixed> $arguments */
    public function leaseFromArguments(array $arguments, ResolvedTool $resolved): ?LeaseGranted
    {
        $modelUse = ModelUse::fromInvocationArguments($arguments);
        $costBudget = CostBudget::fromInvocationArguments($arguments);
        $leaseArg = $arguments['lease'] ?? null;
        $base = $this->baseLease($leaseArg);
        if ($base instanceof LeaseGranted) {
            return $this->overlayLease($base, $leaseArg, $modelUse, $costBudget);
        }
        if (!$modelUse instanceof ModelUse && !$costBudget instanceof CostBudget) {
            return null;
        }
        return new LeaseGranted(
            LeaseId::random(),
            'tool.invoke',
            $resolved->name,
            $resolved->version === null ? 'run' : 'run@' . $resolved->version,
            $this->expiresAt($leaseArg) ?? $this->runtime->clock->now()->modify('+5 minutes'),
            $modelUse,
            $costBudget,
        );
    }

    /**
     * @return list<Credential>|null Null means issuance failed before job.accepted.
     */
    public function issue(Session $session, Envelope $env, Job $job, ?LeaseGranted $lease): ?array
    {
        $provisioner = $this->runtime->credentialProvisioner;
        if (!$provisioner instanceof CredentialProvisioner || !$lease instanceof LeaseGranted) {
            return [];
        }
        if (!$this->shouldIssue($session, $lease)) {
            return [];
        }
        try {
            $credentials = $provisioner->issue($lease, $this->context($session, $env, $job));
        } catch (ARCPException $e) {
            $this->failBeforeAccepted($session, $env, $job, ErrorPayload::fromException($e));
            return null;
        } catch (\Throwable $e) {
            $this->failBeforeAccepted($session, $env, $job, new ErrorPayload(
                'FAILED_PRECONDITION',
                'credential provisioning failed: ' . $e->getMessage(),
                false,
            ));
            return null;
        }
        foreach ($credentials as $credential) {
            $this->runtime->credentials->add($job->id, $credential);
        }
        return $credentials;
    }

    public function revoke(Job $job): void
    {
        $provisioner = $this->runtime->credentialProvisioner;
        if (!$provisioner instanceof CredentialProvisioner) {
            return;
        }
        foreach ($this->runtime->credentials->forJob($job->id) as $credential) {
            $this->revokeCredential($provisioner, $credential->id);
            $this->runtime->credentials->remove($job->id, $credential->id);
        }
    }

    private function baseLease(mixed $leaseArg): ?LeaseGranted
    {
        if (\is_string($leaseArg)) {
            return $this->runtime->leases->get(new LeaseId($leaseArg));
        }
        if (\is_array($leaseArg) && isset($leaseArg['lease_id']) && \is_string($leaseArg['lease_id'])) {
            return $this->runtime->leases->get(new LeaseId($leaseArg['lease_id']));
        }
        return null;
    }

    private function overlayLease(
        LeaseGranted $base,
        mixed $leaseArg,
        ?ModelUse $modelUse,
        ?CostBudget $costBudget,
    ): LeaseGranted {
        return new LeaseGranted(
            $base->leaseId,
            $base->permission,
            $base->resource,
            $base->operation,
            $this->expiresAt($leaseArg) ?? $base->expiresAt,
            $modelUse ?? $base->modelUse,
            $costBudget ?? $base->costBudget,
        );
    }

    private function expiresAt(mixed $leaseArg): ?\DateTimeImmutable
    {
        if (!\is_array($leaseArg)) {
            return null;
        }
        $constraints = $leaseArg['lease_constraints'] ?? null;
        $expiresAt = $leaseArg['expires_at'] ?? null;
        if ($expiresAt === null && \is_array($constraints)) {
            $expiresAt = $constraints['expires_at'] ?? null;
        }
        return \is_string($expiresAt) ? new \DateTimeImmutable($expiresAt) : null;
    }

    private function shouldIssue(Session $session, LeaseGranted $lease): bool
    {
        return $this->sessionAcceptedCredentials($session)
            && ($lease->modelUse !== null || $lease->costBudget !== null);
    }

    private function sessionAcceptedCredentials(Session $session): bool
    {
        return $session->capabilities !== null
            && \in_array('provisioned_credentials', $session->capabilities->features, true);
    }

    private function context(Session $session, Envelope $env, Job $job): JobContext
    {
        $sid = $session->sessionId ?? throw new InvalidArgumentException('session has no id');
        return new JobContext($this->runtime, $session, $job->id, $sid, $env->traceId);
    }

    private function failBeforeAccepted(
        Session $session,
        Envelope $env,
        Job $job,
        ErrorPayload $payload,
    ): void {
        $this->runtime->jobs->transition($job, JobState::Failed);
        $this->runtime->emit($session, new ToolError($payload), [
            'correlation_id' => $env->id,
            'job_id' => $job->id,
            'trace_id' => $env->traceId,
        ]);
    }

    private function revokeCredential(CredentialProvisioner $provisioner, string $credentialId): void
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $provisioner->revoke($credentialId);
                return;
            } catch (\Throwable $e) {
                if ($attempt === 2) {
                    $this->runtime->logger->warning(
                        'credential revocation failed',
                        ['credential_id' => $credentialId, 'error' => $e->getMessage()],
                    );
                }
            }
        }
    }
}
