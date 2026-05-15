<?php

declare(strict_types=1);

namespace Arcp\Messages\Streaming;

use Arcp\Envelope\MessageType;

/** RFC §11 — graceful stream end. */
final readonly class StreamClose extends MessageType
{
    public function __construct(public ?int $totalChunks = null)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'stream.close';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->totalChunks !== null ? ['total_chunks' => $this->totalChunks] : [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $total = null;
        if (isset($data['total_chunks']) && \is_int($data['total_chunks'])) {
            $total = $data['total_chunks'];
        }
        return new self($total);
    }
}
