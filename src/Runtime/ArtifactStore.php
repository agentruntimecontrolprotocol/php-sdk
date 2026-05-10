<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Errors\NotFoundException;
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

    public function put(Session $session, string $mediaType, string $bytes, ?int $retentionSeconds = null): ArtifactRef
    {
        $sessionId = (string) ($session->sessionId ?? throw new \Arcp\Errors\InvalidArgumentException('session has no id'));
        $retention = $retentionSeconds ?? $this->defaultRetentionSeconds;
        $retention = min($retention, $this->maxRetentionSeconds);
        $id = ArtifactId::random();
        $expiresAt = $this->clock->now()->modify('+' . $retention . ' seconds');
        $ref = new ArtifactRef(
            artifactId: $id,
            uri: 'arcp://session/' . $sessionId . '/artifact/' . (string) $id,
            mediaType: $mediaType,
            size: \strlen($bytes),
            sha256: hash('sha256', $bytes),
            expiresAt: $expiresAt,
        );
        $this->artifacts[(string) $id] = [
            'ref' => $ref,
            'bytes' => $bytes,
            'session_key' => $sessionId,
        ];
        return $ref;
    }

    public function fetch(ArtifactId $id): string
    {
        $row = $this->artifacts[(string) $id] ?? throw new NotFoundException(\sprintf('artifact %s not found', $id));
        if (($row['ref']->expiresAt ?? null) !== null && $row['ref']->expiresAt <= $this->clock->now()) {
            unset($this->artifacts[(string) $id]);
            throw new NotFoundException(\sprintf('artifact %s expired', $id));
        }
        return $row['bytes'];
    }

    public function ref(ArtifactId $id): ArtifactRef
    {
        $row = $this->artifacts[(string) $id] ?? throw new NotFoundException(\sprintf('artifact %s not found', $id));
        return $row['ref'];
    }

    public function release(ArtifactId $id): bool
    {
        if (!isset($this->artifacts[(string) $id])) {
            return false;
        }
        unset($this->artifacts[(string) $id]);
        return true;
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
