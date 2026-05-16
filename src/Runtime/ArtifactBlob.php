<?php

declare(strict_types=1);

namespace Arcp\Runtime;

/**
 * Parameter object for {@see ArtifactStore::put()} bundling the artifact
 * payload metadata so the call signature stays under the size budget.
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
