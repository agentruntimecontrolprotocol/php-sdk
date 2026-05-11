<?php

declare(strict_types=1);

namespace Arcp\Samples\Subscriptions;

use Arcp\Envelope\Envelope;

final class SqliteSink
{
    public function __construct(public readonly string $path)
    {
    }

    public function handle(Envelope $env): void
    {
        // Real version uses Arcp\Store\eventlog schema over PDO sqlite.
        throw new \RuntimeException('not implemented');
    }
}
