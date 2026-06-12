<?php

declare(strict_types=1);

namespace Arcp\Client;

use Arcp\Errors\InvalidArgumentException;
use Arcp\Messages\Execution\ResultChunk;
use Arcp\Messages\Execution\ResultChunkEncoding;

/**
 * Collects `job.result_chunk` messages by result id and assembles final
 * bytes. Enforces sequence contiguity (0..N), terminal-chunk delivery,
 * and duplicate consistency so a truncated or out-of-order stream never
 * silently produces a corrupted result.
 */
final class ResultChunkAssembler
{
    /** @var array<string, array<int, ResultChunk>> */
    private array $chunks = [];

    /** @var array<string, bool> */
    private array $complete = [];

    public function push(ResultChunk $chunk): void
    {
        $existing = $this->chunks[$chunk->resultId][$chunk->chunkSeq] ?? null;
        if ($existing instanceof ResultChunk && !$this->sameChunkPayload($existing, $chunk)) {
            throw new InvalidArgumentException(
                'result_chunk duplicate with conflicting payload',
                ['result_id' => $chunk->resultId, 'chunk_seq' => $chunk->chunkSeq],
            );
        }
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
        if (!isset($this->complete[$resultId])) {
            throw new InvalidArgumentException(
                'result_chunk stream incomplete: terminal chunk not yet received',
                ['result_id' => $resultId],
            );
        }
        ksort($chunks);
        $this->assertContiguous($resultId, $chunks);
        $out = '';
        foreach ($chunks as $chunk) {
            $out .= $chunk->encoding === ResultChunkEncoding::Base64
                ? $this->decodeBase64($chunk)
                : $chunk->data;
        }
        $this->forget($resultId);
        return $out;
    }

    /**
     * Release all buffered chunks for an assembled (or abandoned) result so a
     * long-lived client streaming many results does not grow without bound.
     */
    public function forget(string $resultId): void
    {
        unset($this->chunks[$resultId], $this->complete[$resultId]);
    }

    /** @param array<int, ResultChunk> $chunks */
    private function assertContiguous(string $resultId, array $chunks): void
    {
        $expected = 0;
        $terminal = null;
        foreach ($chunks as $seq => $chunk) {
            if ($seq !== $expected) {
                throw new InvalidArgumentException(
                    'result_chunk sequence not contiguous from 0',
                    ['result_id' => $resultId, 'expected_seq' => $expected, 'actual_seq' => $seq],
                );
            }
            ++$expected;
            $terminal = $chunk;
        }
        if ($terminal instanceof ResultChunk && $terminal->more) {
            throw new InvalidArgumentException(
                'result_chunk highest sequence has more=true',
                ['result_id' => $resultId],
            );
        }
    }

    private function sameChunkPayload(ResultChunk $a, ResultChunk $b): bool
    {
        return $a->data === $b->data
            && $a->encoding === $b->encoding
            && $a->more === $b->more;
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
