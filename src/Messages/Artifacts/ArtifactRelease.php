<?php

declare(strict_types=1);

namespace Arcp\Messages\Artifacts;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\ArtifactId;

/** RFC §16.2 — caller no longer needs the artifact; runtime MAY GC. */
final readonly class ArtifactRelease extends MessageType
{
    public function __construct(public ArtifactId $artifactId)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'artifact.release';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['artifact_id' => (string) $this->artifactId];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['artifact_id'] ?? throw new InvalidArgumentException('artifact_id missing');
        return new static(ArtifactId::fromJson($id));
    }
}
