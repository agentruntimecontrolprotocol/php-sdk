<?php

declare(strict_types=1);

namespace Arcp\Messages\Artifacts;

use Arcp\Envelope\MessageType;

/**
 * SDK artifact extension — acknowledges `artifact.release`. `released`
 * is true when the runtime deleted the artifact, false when the id was
 * already unknown or expired.
 */
final readonly class ArtifactReleased extends MessageType
{
    public function __construct(public bool $released)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'artifact.released';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['released' => $this->released];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(($data['released'] ?? false) === true);
    }
}
