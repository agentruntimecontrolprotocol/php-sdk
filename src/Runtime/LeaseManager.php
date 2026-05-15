<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Errors\PermissionDeniedException;
use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Errors\LeaseExpiredException;
use Arcp\Errors\LeaseRevokedException;
use Arcp\Errors\NotFoundException;
use Arcp\Ids\LeaseId;
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

    /** @var array<string, string> revoked lease id → reason */
    private array $revoked = [];

    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    public function register(LeaseGranted $lease): void
    {
        $this->byId[(string) $lease->leaseId] = $lease;
    }

    public function get(LeaseId $id): LeaseGranted
    {
        $key = (string) $id;
        if (isset($this->revoked[$key])) {
            throw new LeaseRevokedException($id, $this->revoked[$key]);
        }
        $lease = $this->byId[$key] ?? throw new NotFoundException(\sprintf('lease %s not found', $id));
        if ($lease->expiresAt <= $this->clock->now()) {
            throw new LeaseExpiredException($id, $lease->expiresAt);
        }
        return $lease;
    }

    public function ensureUsable(LeaseId $id, string $permission, string $resource, string $operation): LeaseGranted
    {
        $lease = $this->get($id);
        if ($lease->permission !== $permission || $lease->resource !== $resource || $lease->operation !== $operation) {
            throw new PermissionDeniedException($permission, $resource, 'lease scope mismatch');
        }
        return $lease;
    }

    public function extend(LeaseId $id, \DateTimeImmutable $newExpiresAt): LeaseGranted
    {
        $lease = $this->get($id);
        $extended = new LeaseGranted(
            $lease->leaseId,
            $lease->permission,
            $lease->resource,
            $lease->operation,
            $newExpiresAt,
        );
        $this->byId[(string) $id] = $extended;
        return $extended;
    }

    public function revoke(LeaseId $id, string $reason = ''): LeaseRevoked
    {
        $key = (string) $id;
        if (isset($this->byId[$key])) {
            unset($this->byId[$key]);
        }
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
