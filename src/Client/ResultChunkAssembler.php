<?php

declare(strict_types=1);

namespace Arcp\Client;

use Arcp\Errors\InvalidRequestException;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Execution\ResultChunkEncoding;

/**
 * Collects §8.4 `result_chunk` job events by `result_id` and assembles
 * final bytes. Enforces sequence contiguity (0..N), terminal-chunk
 * delivery, and duplicate consistency so a truncated, out-of-order, or
 * divergent stream never silently produces a corrupted result.
 */
final class ResultChunkAssembler
{
    /**
     * @var array<string, array<int, array{data: string, encoding: ResultChunkEncoding, more: bool}>>
     */
    private array $chunks = [];

    /** @var array<string, bool> */
    private array $complete = [];

    /**
     * Ingest a `job.event`; events whose kind is not `result_chunk` are
     * ignored. Byte-identical duplicates are tolerated (§8.4); a
     * divergent duplicate raises.
     */
    public function push(JobEvent $event): void
    {
        if ($event->eventKind !== 'result_chunk') {
            return;
        }
        $chunk = $this->parse($event->body);
        $resultId = $chunk['result_id'];
        $seq = $chunk['chunk_seq'];
        $existing = $this->chunks[$resultId][$seq] ?? null;
        $record = ['data' => $chunk['data'], 'encoding' => $chunk['encoding'], 'more' => $chunk['more']];
        if ($existing !== null && $existing !== $record) {
            throw new InvalidRequestException(
                'result_chunk duplicate with conflicting payload',
                ['result_id' => $resultId, 'chunk_seq' => $seq],
            );
        }
        $this->chunks[$resultId][$seq] = $record;
        if (!$chunk['more']) {
            $this->complete[$resultId] = true;
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
            throw new InvalidRequestException('unknown result_id: ' . $resultId);
        }
        if (!isset($this->complete[$resultId])) {
            throw new InvalidRequestException(
                'result_chunk stream incomplete: terminal chunk not yet received',
                ['result_id' => $resultId],
            );
        }
        ksort($chunks);
        $this->assertContiguous($resultId, $chunks);
        $out = '';
        foreach ($chunks as $chunk) {
            $out .= $chunk['encoding'] === ResultChunkEncoding::Base64
                ? $this->decodeBase64($chunk['data'])
                : $chunk['data'];
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

    /**
     * Validate and narrow a §8.4 `result_chunk` body.
     *
     * @param array<string, mixed> $body
     *
     * @return array{result_id: string, chunk_seq: int, data: string, encoding: ResultChunkEncoding, more: bool}
     */
    private function parse(array $body): array
    {
        $resultId = $body['result_id'] ?? throw new InvalidRequestException('result_chunk result_id missing');
        $seq = $body['chunk_seq'] ?? throw new InvalidRequestException('result_chunk chunk_seq missing');
        $data = $body['data'] ?? throw new InvalidRequestException('result_chunk data missing');
        $encoding = $body['encoding'] ?? 'utf8';
        $more = $body['more'] ?? throw new InvalidRequestException('result_chunk more missing');
        if (!\is_string($resultId) || $resultId === '' || !\is_int($seq) || !\is_string($data)) {
            throw new InvalidRequestException('result_id/data must be strings; chunk_seq must be int');
        }
        if ($seq < 0) {
            throw new InvalidRequestException('chunk_seq must be non-negative');
        }
        if (!\is_string($encoding) || !\is_bool($more)) {
            throw new InvalidRequestException('encoding/more have invalid types');
        }
        $encodingEnum = ResultChunkEncoding::tryFrom($encoding)
            ?? throw new InvalidRequestException('encoding must be utf8 or base64');
        return [
            'result_id' => $resultId,
            'chunk_seq' => $seq,
            'data' => $data,
            'encoding' => $encodingEnum,
            'more' => $more,
        ];
    }

    /** @param array<int, array{data: string, encoding: ResultChunkEncoding, more: bool}> $chunks */
    private function assertContiguous(string $resultId, array $chunks): void
    {
        $expected = 0;
        $terminal = null;
        foreach ($chunks as $seq => $chunk) {
            if ($seq !== $expected) {
                throw new InvalidRequestException(
                    'result_chunk sequence not contiguous from 0',
                    ['result_id' => $resultId, 'expected_seq' => $expected, 'actual_seq' => $seq],
                );
            }
            ++$expected;
            $terminal = $chunk;
        }
        if ($terminal !== null && $terminal['more']) {
            throw new InvalidRequestException(
                'result_chunk highest sequence has more=true',
                ['result_id' => $resultId],
            );
        }
    }

    private function decodeBase64(string $data): string
    {
        $decoded = base64_decode($data, strict: true);
        if ($decoded === false) {
            throw new InvalidRequestException('result_chunk data is not valid base64');
        }
        return $decoded;
    }
}
