<?php

declare(strict_types=1);

/**
 * §8.4: result chunks ride as `job.event` payloads of kind
 * `result_chunk` with body {result_id, chunk_seq, data, encoding, more}.
 *
 * @return list<array{kind: string, body: array{result_id: string, chunk_seq: int, data: string, encoding: string, more: bool}}>
 */
function streamResultChunks(string $resultId, string $text, int $chunkSize): array
{
    $pieces = str_split($text, $chunkSize);
    $chunks = [];
    foreach ($pieces as $offset => $chunk) {
        $chunks[] = [
            'kind' => 'result_chunk',
            'body' => [
                'result_id' => $resultId,
                'chunk_seq' => $offset,
                'data' => $chunk,
                'encoding' => 'utf8',
                'more' => $offset < count($pieces) - 1,
            ],
        ];
    }

    return $chunks;
}

print json_encode(streamResultChunks('res_demo', 'resume across reconnects', 8), JSON_THROW_ON_ERROR) . "\n";
