<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Errors\InvalidRequestException;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Ids\ArtifactId;
use Arcp\Messages\Artifacts\ArtifactRef;

/**
 * In-memory artifact store keyed by session (RFC §16). Implements the
 * inline-base64 path for v0.1; sidecar binary frames are deferred.
 *
 * Retention: every put is stamped with `expires_at`. The store does not
 * spawn its own sweep fiber; the runtime calls {@see sweep()} on a
 * periodic timer.
 *
 * @phpstan-type StoredArtifact array{
 *     ref: ArtifactRef,
 *     bytes: string,
 *     session_key: string,
 * }
 */
final class ArtifactStore
{
    /** @var array<string, StoredArtifact> indexed by artifact id */
    private array $artifacts = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
        public readonly int $defaultRetentionSeconds = 86400,
        public readonly int $maxRetentionSeconds = 604800,
    ) {
    }

    public function put(Session $session, ArtifactBlob $blob): ArtifactRef
    {
        $sessionId = (string) (
            $session->sessionId
            ?? throw new InvalidRequestException('session has no id')
        );
        $retention = $blob->retentionSeconds ?? $this->defaultRetentionSeconds;
        $retention = min($retention, $this->maxRetentionSeconds);
        $id = ArtifactId::random();
        $expiresAt = $this->clock->now()->modify('+' . $retention . ' seconds');
        $ref = new ArtifactRef(
            artifactId: $id,
            uri: 'arcp://session/' . $sessionId . '/artifact/' . $id->value,
            mediaType: $blob->mediaType,
            size: \strlen($blob->bytes),
            sha256: hash('sha256', $blob->bytes),
            expiresAt: $expiresAt,
        );
        $this->artifacts[(string) $id] = [
            'ref' => $ref,
            'bytes' => $blob->bytes,
            'session_key' => $sessionId,
        ];
        return $ref;
    }

    public function fetch(ArtifactId $id, ?Session $session = null): string
    {
        $row = $this->artifacts[(string) $id]
            ?? throw new InvalidRequestException(\sprintf('artifact %s not found', $id));
        $this->assertOwnership($id, $row, $session);
        $this->assertNotExpired($id, $row);
        return $row['bytes'];
    }

    public function ref(ArtifactId $id, ?Session $session = null): ArtifactRef
    {
        $row = $this->artifacts[(string) $id]
            ?? throw new InvalidRequestException(\sprintf('artifact %s not found', $id));
        $this->assertOwnership($id, $row, $session);
        $this->assertNotExpired($id, $row);
        return $row['ref'];
    }

    /**
     * Drop and reject an artifact whose ref has expired, so ref() and
     * fetch() agree on visibility.
     *
     * @param StoredArtifact $row
     */
    private function assertNotExpired(ArtifactId $id, array $row): void
    {
        $expiresAt = $row['ref']->expiresAt;
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            unset($this->artifacts[(string) $id]);
            throw new InvalidRequestException(\sprintf('artifact %s expired', $id));
        }
    }

    public function release(ArtifactId $id, ?Session $session = null): bool
    {
        $row = $this->artifacts[(string) $id] ?? null;
        if ($row === null) {
            return false;
        }
        $this->assertOwnership($id, $row, $session);
        unset($this->artifacts[(string) $id]);
        return true;
    }

    /**
     * @param StoredArtifact $row
     */
    private function assertOwnership(ArtifactId $id, array $row, ?Session $session): void
    {
        if ($session === null) {
            return; // Trusted internal caller (no session context).
        }
        $sessionId = $session->sessionId;
        if ($sessionId === null || (string) $sessionId !== $row['session_key']) {
            throw new PermissionDeniedException(
                permission: 'artifact.access',
                resource: (string) $id,
                message: \sprintf('artifact %s does not belong to this session', $id),
            );
        }
    }

    /** Remove every expired artifact. */
    public function sweep(): int
    {
        $now = $this->clock->now();
        $removed = 0;
        foreach ($this->artifacts as $key => $row) {
            $exp = $row['ref']->expiresAt;
            if ($exp !== null && $exp <= $now) {
                unset($this->artifacts[$key]);
                ++$removed;
            }
        }
        return $removed;
    }

    public function count(): int
    {
        return \count($this->artifacts);
    }
}
