<?php

declare(strict_types=1);

namespace Arcp\Runtime;

/**
 * Bundles the bytes and metadata for an artifact written to
 * {@see ArtifactStore::put()}.
 */
final readonly class ArtifactBlob
{
    public function __construct(
        public string $mediaType,
        public string $bytes,
        public ?int $retentionSeconds = null,
    ) {
    }
}
