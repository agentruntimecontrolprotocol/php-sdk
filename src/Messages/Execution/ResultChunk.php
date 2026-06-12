<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** ARCP v1.1 §8.4 — streamed terminal result chunk. */
final readonly class ResultChunk extends MessageType
{
    public function __construct(
        public string $resultId,
        public int $chunkSeq,
        public string $data,
        public ResultChunkEncoding $encoding = ResultChunkEncoding::Utf8,
        public bool $more = true,
    ) {
        if ($resultId === '') {
            throw new InvalidRequestException('result_id missing');
        }
        if ($chunkSeq < 0) {
            throw new InvalidRequestException('chunk_seq must be non-negative');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.result_chunk';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'result_id' => $this->resultId,
            'chunk_seq' => $this->chunkSeq,
            'data' => $this->data,
            'encoding' => $this->encoding->value,
            'more' => $this->more,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $resultId = $data['result_id'] ?? throw new InvalidRequestException('result_id missing');
        $chunkSeq = $data['chunk_seq'] ?? throw new InvalidRequestException('chunk_seq missing');
        $chunkData = $data['data'] ?? throw new InvalidRequestException('data missing');
        $encoding = $data['encoding'] ?? 'utf8';
        $more = $data['more'] ?? true;
        if (!\is_string($resultId) || !\is_int($chunkSeq) || !\is_string($chunkData)) {
            throw new InvalidRequestException('result_id/data must be strings; chunk_seq must be int');
        }
        if (!\is_string($encoding) || !\is_bool($more)) {
            throw new InvalidRequestException('encoding/more have invalid types');
        }
        $encodingEnum = ResultChunkEncoding::tryFrom($encoding)
            ?? throw new InvalidRequestException('encoding must be utf8 or base64');
        return new self($resultId, $chunkSeq, $chunkData, $encodingEnum, $more);
    }
}
