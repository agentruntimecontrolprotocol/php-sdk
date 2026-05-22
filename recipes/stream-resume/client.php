<?php

declare(strict_types=1);

/**
 * @param list<array{seq: int, data: string}> $chunks
 */
function assembleChunks(array $chunks): string
{
    usort($chunks, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);

    return implode('', array_column($chunks, 'data'));
}

print assembleChunks([
    ['seq' => 2, 'data' => 'sumed'],
    ['seq' => 1, 'data' => 're'],
]) . "\n";
