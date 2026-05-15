<?php

declare(strict_types=1);

namespace Arcp\Messages\Streaming;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §11.1 — declare a new stream. */
final readonly class StreamOpen extends MessageType
{
    public function __construct(
        public StreamKind $kind,
        public ?string $contentType = null,
        public ?string $encoding = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'stream.open';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['kind' => $this->kind->value];
        if ($this->contentType !== null) {
            $out['content_type'] = $this->contentType;
        }
        if ($this->encoding !== null) {
            $out['encoding'] = $this->encoding;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $kind = $data['kind'] ?? throw new InvalidArgumentException('kind missing');
        if (!\is_string($kind)) {
            throw new InvalidArgumentException('kind must be string');
        }
        $contentType = null;
        if (isset($data['content_type']) && \is_string($data['content_type'])) {
            $contentType = $data['content_type'];
        }
        $encoding = null;
        if (isset($data['encoding']) && \is_string($data['encoding'])) {
            $encoding = $data['encoding'];
        }
        return new self(StreamKind::parse($kind), $contentType, $encoding);
    }
}
