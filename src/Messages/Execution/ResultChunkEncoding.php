<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

/** ARCP v1.1 §8.4 — `result_chunk` data encoding. */
enum ResultChunkEncoding: string
{
    case Utf8 = 'utf8';
    case Base64 = 'base64';
}
