<?php

declare(strict_types=1);

namespace Arcp\Messages\Streaming;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §11 — incremental stream payload. */
final readonly class StreamChunk extends MessageType
{
    public function __construct(
        public int $sequence,
        public mixed $data = null,
        public ?string $contentType = null,
        public ?string $sha256 = null,
        public ?string $role = null,
        public ?string $content = null,
        public bool $redacted = false,
    ) {
        if ($sequence < 0) {
            throw new InvalidArgumentException('sequence must be ≥ 0');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'stream.chunk';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['sequence' => $this->sequence];
        if ($this->data !== null) {
            $out['data'] = $this->data;
        }
        if ($this->contentType !== null) {
            $out['content_type'] = $this->contentType;
        }
        if ($this->sha256 !== null) {
            $out['sha256'] = $this->sha256;
        }
        if ($this->role !== null) {
            $out['role'] = $this->role;
        }
        if ($this->content !== null) {
            $out['content'] = $this->content;
        }
        if ($this->redacted) {
            $out['redacted'] = true;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $seq = $data['sequence'] ?? throw new InvalidArgumentException('sequence missing');
        if (!\is_int($seq)) {
            throw new InvalidArgumentException('sequence must be int');
        }
        return new self(
            sequence: $seq,
            data: $data['data'] ?? null,
            contentType: isset($data['content_type']) && \is_string($data['content_type'])
                ? $data['content_type']
                : null,
            sha256: isset($data['sha256']) && \is_string($data['sha256'])
                ? $data['sha256']
                : null,
            role: isset($data['role']) && \is_string($data['role']) ? $data['role'] : null,
            content: isset($data['content']) && \is_string($data['content'])
                ? $data['content']
                : null,
            redacted: isset($data['redacted']) && $data['redacted'] === true,
        );
    }
}
