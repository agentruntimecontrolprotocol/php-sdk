<?php

declare(strict_types=1);

/**
 * @return list<array{seq: int, type: string, data: string}>
 */
function streamResultChunks(string $text, int $chunkSize): array
{
    $chunks = [];
    foreach (str_split($text, $chunkSize) as $offset => $chunk) {
        $chunks[] = ['seq' => $offset + 1, 'type' => 'job.result_chunk', 'data' => $chunk];
    }

    return $chunks;
}

print json_encode(streamResultChunks('resume across reconnects', 8), JSON_THROW_ON_ERROR) . "\n";
