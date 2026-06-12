<?php

declare(strict_types=1);

namespace Arcp\Messages\Artifacts;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\ArtifactId;

/** RFC §16.1 — canonical pointer to an artifact. */
final readonly class ArtifactRef extends MessageType
{
    /** @size-check-suppress Wire-shape DTO mapping to RFC §16.1. */
    public function __construct(
        public ArtifactId $artifactId,
        public string $uri,
        public string $mediaType,
        public ?int $size = null,
        public ?string $sha256 = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
        if ($uri === '' || $mediaType === '') {
            throw new InvalidRequestException('uri/media_type missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'artifact.ref';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'artifact_id' => (string) $this->artifactId,
            'uri' => $this->uri,
            'media_type' => $this->mediaType,
        ];
        if ($this->size !== null) {
            $out['size'] = $this->size;
        }
        if ($this->sha256 !== null) {
            $out['sha256'] = $this->sha256;
        }
        if ($this->expiresAt instanceof \DateTimeImmutable) {
            $out['expires_at'] = $this->expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED);
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['artifact_id'] ?? throw new InvalidRequestException('artifact_id missing');
        $uri = $data['uri'] ?? throw new InvalidRequestException('uri missing');
        $mt = $data['media_type'] ?? throw new InvalidRequestException('media_type missing');
        if (!\is_string($uri) || !\is_string($mt)) {
            throw new InvalidRequestException('uri/media_type must be strings');
        }
        $size = null;
        if (isset($data['size']) && \is_int($data['size'])) {
            $size = $data['size'];
        }
        $sha = null;
        if (isset($data['sha256']) && \is_string($data['sha256'])) {
            $sha = $data['sha256'];
        }
        $exp = null;
        if (isset($data['expires_at']) && \is_string($data['expires_at'])) {
            $exp = new \DateTimeImmutable($data['expires_at']);
        }
        return new self(ArtifactId::fromJson($id), $uri, $mt, $size, $sha, $exp);
    }
}
