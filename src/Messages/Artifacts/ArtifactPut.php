<?php

declare(strict_types=1);

namespace Arcp\Messages\Artifacts;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §16.2 — upload an artifact (inline base64 in v0.1). */
final readonly class ArtifactPut extends MessageType
{
    public function __construct(
        public string $mediaType,
        public string $data,
        public ?int $retentionSeconds = null,
        public ?string $sha256 = null,
    ) {
        if ($mediaType === '') {
            throw new InvalidArgumentException('media_type missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'artifact.put';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['media_type' => $this->mediaType, 'data' => $this->data];
        if ($this->retentionSeconds !== null) {
            $out['retention_seconds'] = $this->retentionSeconds;
        }
        if ($this->sha256 !== null) {
            $out['sha256'] = $this->sha256;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $mt = $data['media_type'] ?? throw new InvalidArgumentException('media_type missing');
        $body = $data['data'] ?? throw new InvalidArgumentException('data missing');
        if (!\is_string($mt) || !\is_string($body)) {
            throw new InvalidArgumentException('media_type/data must be strings');
        }
        $ret = null;
        if (isset($data['retention_seconds']) && \is_int($data['retention_seconds'])) {
            $ret = $data['retention_seconds'];
        }
        $sha = null;
        if (isset($data['sha256']) && \is_string($data['sha256'])) {
            $sha = $data['sha256'];
        }
        return new static($mt, $body, $ret, $sha);
    }
}
