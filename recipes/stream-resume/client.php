<?php

declare(strict_types=1);

/**
 * §8.4: reassemble decoded chunk data in `chunk_seq` order.
 *
 * @param list<array{chunk_seq: int, data: string}> $chunks
 */
function assembleChunks(array $chunks): string
{
    usort($chunks, static fn (array $a, array $b): int => $a['chunk_seq'] <=> $b['chunk_seq']);

    return implode('', array_column($chunks, 'data'));
}

print assembleChunks([
    ['chunk_seq' => 1, 'data' => 'sumed'],
    ['chunk_seq' => 0, 'data' => 're'],
]) . "\n";
