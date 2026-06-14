<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Errors\LeaseExpiredException;
use Arcp\Errors\LeaseSubsetViolationException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Ids\LeaseId;
use Arcp\Ids\SessionId;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Messages\Permissions\LeaseRevoked;

/**
 * Tracks the {@see LeaseGranted} state per session (RFC §15.5). Throws
 * the right typed exception for revoked / expired / unknown lookups.
 */
final class LeaseManager
{
    /** @var array<string, LeaseGranted> */
    private array $byId = [];

    /** @var array<string, string> lease id → granting session id */
    private array $owners = [];

    /** @var array<string, string> revoked lease id → reason */
    private array $revoked = [];

    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    public function register(LeaseGranted $lease, ?SessionId $sessionId = null): void
    {
        $this->byId[(string) $lease->leaseId] = $lease;
        if ($sessionId instanceof SessionId) {
            $this->owners[(string) $lease->leaseId] = (string) $sessionId;
        }
    }

    public function get(LeaseId $id): LeaseGranted
    {
        $key = (string) $id;
        if (isset($this->revoked[$key])) {
            // §12: operations on a revoked lease are rejected by lease
            // enforcement — PERMISSION_DENIED.
            throw new PermissionDeniedException(
                permission: 'lease.use',
                resource: $key,
                message: \sprintf('lease %s revoked: %s', $id, $this->revoked[$key]),
            );
        }
        $lease = $this->byId[$key]
            ?? throw new PermissionDeniedException(
                permission: 'lease.use',
                resource: $key,
                message: \sprintf('lease %s not found', $id),
            );
        if ($lease->expiresAt <= $this->clock->now()) {
            throw new LeaseExpiredException($id, $lease->expiresAt);
        }
        return $lease;
    }

    /**
     * Like {@see get()}, but refuses to return a lease that was granted to
     * a different session. Returns the lease only when the requesting
     * session is the registered owner (or when the lease has no recorded
     * owner, for legacy registrations).
     *
     * @throws PermissionDeniedException when the lease belongs to another session.
     */
    public function getForSession(LeaseId $id, SessionId $sessionId): LeaseGranted
    {
        $lease = $this->get($id);
        $owner = $this->owners[(string) $id] ?? null;
        if ($owner !== null && $owner !== (string) $sessionId) {
            throw new PermissionDeniedException(
                permission: 'lease.use',
                resource: (string) $id,
                message: \sprintf('lease %s does not belong to this session', $id),
            );
        }
        return $lease;
    }

    /**
     * Resolve a lease and assert it matches the requested scope. When
     * `$sessionId` is supplied the lookup is session-scoped (leases are
     * session-scoped per RFC §15.5), so a caller holding a lease id from a
     * different session cannot pass scope checks.
     *
     * @throws PermissionDeniedException when the lease belongs to another
     *                                   session or the scope does not match.
     */
    public function ensureUsable(LeaseId $id, LeaseScope $scope, ?SessionId $sessionId = null): LeaseGranted
    {
        $lease = $sessionId instanceof SessionId
            ? $this->getForSession($id, $sessionId)
            : $this->get($id);
        if (
            $lease->permission !== $scope->permission
            || $lease->resource !== $scope->resource
            || $lease->operation !== $scope->operation
        ) {
            throw new PermissionDeniedException(
                $scope->permission,
                $scope->resource,
                'lease scope mismatch',
            );
        }
        return $lease;
    }

    public function ensureSubset(LeaseGranted $parent, LeaseGranted $child): void
    {
        if (
            $parent->modelUse !== null
            && $child->modelUse !== null
            && !$parent->modelUse->containsSubset($child->modelUse)
        ) {
            throw new LeaseSubsetViolationException(
                (string) $parent->leaseId,
                (string) $child->leaseId,
                'model.use',
            );
        }
        if ($parent->modelUse === null && $child->modelUse !== null) {
            throw new LeaseSubsetViolationException(
                (string) $parent->leaseId,
                (string) $child->leaseId,
                'model.use',
            );
        }
        if (
            $parent->costBudget !== null
            && $child->costBudget !== null
            && !$parent->costBudget->containsSubset($child->costBudget)
        ) {
            throw new LeaseSubsetViolationException(
                (string) $parent->leaseId,
                (string) $child->leaseId,
                'cost.budget',
            );
        }
        if ($parent->costBudget === null && $child->costBudget !== null) {
            throw new LeaseSubsetViolationException(
                (string) $parent->leaseId,
                (string) $child->leaseId,
                'cost.budget',
            );
        }
        // §9.4: a delegated lease's expires_at MUST NOT exceed the parent's.
        if ($child->expiresAt > $parent->expiresAt) {
            throw new LeaseSubsetViolationException(
                (string) $parent->leaseId,
                (string) $child->leaseId,
                'lease_constraints.expires_at',
            );
        }
    }

    public function revoke(LeaseId $id, string $reason = ''): LeaseRevoked
    {
        $key = (string) $id;
        if (isset($this->byId[$key])) {
            unset($this->byId[$key]);
        }
        unset($this->owners[$key]);
        $this->revoked[$key] = $reason;
        return new LeaseRevoked($id, $reason);
    }

    public function isRevoked(LeaseId $id): bool
    {
        return isset($this->revoked[(string) $id]);
    }

    /** @return list<LeaseGranted> */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
