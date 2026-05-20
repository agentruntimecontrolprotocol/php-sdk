<?php

declare(strict_types=1);

namespace Arcp\Client;

use Arcp\Errors\InvalidArgumentException;
use Arcp\Messages\Execution\ResultChunk;

/** Collects `job.result_chunk` messages by result id and assembles final bytes. */
final class ResultChunkAssembler
{
    /** @var array<string, array<int, ResultChunk>> */
    private array $chunks = [];

    /** @var array<string, bool> */
    private array $complete = [];

    public function push(ResultChunk $chunk): void
    {
        $this->chunks[$chunk->resultId][$chunk->chunkSeq] = $chunk;
        if (!$chunk->more) {
            $this->complete[$chunk->resultId] = true;
        }
    }

    public function isComplete(string $resultId): bool
    {
        return isset($this->complete[$resultId]);
    }

    public function assemble(string $resultId): string
    {
        $chunks = $this->chunks[$resultId] ?? [];
        if ($chunks === []) {
            throw new InvalidArgumentException('unknown result_id: ' . $resultId);
        }
        ksort($chunks);
        $out = '';
        foreach ($chunks as $chunk) {
            $out .= $chunk->encoding === 'base64'
                ? $this->decodeBase64($chunk)
                : $chunk->data;
        }
        return $out;
    }

    private function decodeBase64(ResultChunk $chunk): string
    {
        $decoded = base64_decode($chunk->data, strict: true);
        if ($decoded === false) {
            throw new InvalidArgumentException('result_chunk data is not valid base64');
        }
        return $decoded;
    }
}
