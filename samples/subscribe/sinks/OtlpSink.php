<?php

declare(strict_types=1);

namespace Arcp\Samples\Subscriptions;

use Arcp\Envelope\Envelope;

final class OtlpSink
{
    public function __construct(public readonly string $endpoint)
    {
    }

    public function handle(Envelope $env): void
    {
        // Real version forwards `metric` and `trace.span` to OpenTelemetry.
        throw new \RuntimeException('not implemented');
    }
}
