<?php

declare(strict_types=1);

namespace Arcp\Messages\Artifacts;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\ArtifactId;

/** RFC §16.2 — request an artifact by id. */
final readonly class ArtifactFetch extends MessageType
{
    public function __construct(public ArtifactId $artifactId)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'artifact.fetch';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['artifact_id' => (string) $this->artifactId];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['artifact_id'] ?? throw new InvalidRequestException('artifact_id missing');
        return new self(ArtifactId::fromJson($id));
    }
}
