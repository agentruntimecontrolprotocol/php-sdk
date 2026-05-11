<?php

declare(strict_types=1);

namespace Arcp\Samples\Subscriptions;

use Arcp\Envelope\Envelope;

final class StdoutSink
{
    public function handle(Envelope $env): void
    {
        throw new \RuntimeException('not implemented');
    }
}
