<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Symfony\Component\Uid\Ulid;

/**
 * Identifier for an artifact (RFC §16). Artifacts are addressed by id;
 * URIs of the form `arcp://session/{session_id}/artifact/{artifact_id}`
 * embed the same value.
 */
final readonly class ArtifactId extends Id
{
    public static function random(): self
    {
        return new self('art_' . new Ulid()->toBase32());
    }
}
