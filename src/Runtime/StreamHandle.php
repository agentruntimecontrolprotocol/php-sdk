<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Ids\StreamId;

/**
 * Typed handle returned by {@see JobContext::openStream()}. Replaces the
 * former positional `[StreamId, push, close]` tuple so call sites use
 * named methods and the API can evolve without breaking destructuring.
 */
final readonly class StreamHandle
{
    /**
     * @param \Closure(string|array<string, mixed>|null, ?string=): void $pushFn
     * @param \Closure(?int=): void $closeFn
     */
    public function __construct(
        public StreamId $id,
        private \Closure $pushFn,
        private \Closure $closeFn,
    ) {
    }

    /**
     * Emit the next chunk on the stream. A string is sent as text content,
     * an array as structured data; null emits an empty chunk.
     *
     * @param string|array<string, mixed>|null $body
     */
    public function push(string|array|null $body = null, ?string $contentType = null): void
    {
        ($this->pushFn)($body, $contentType);
    }

    /**
     * Close the stream, optionally declaring the total chunk count (it
     * defaults to the number of chunks pushed through this handle).
     */
    public function close(?int $totalChunks = null): void
    {
        ($this->closeFn)($totalChunks);
    }
}
