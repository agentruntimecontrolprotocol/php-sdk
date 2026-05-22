<?php

declare(strict_types=1);

/**
 * @return array{extensions: array<string, string>}
 */
function envelopeWithTraceContext(string $traceparent): array
{
    return [
        'extensions' => [
            'x-vendor.opentelemetry.tracecontext' => $traceparent,
        ],
    ];
}

$envelope = envelopeWithTraceContext('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
foreach ($envelope['extensions'] as $traceparent) {
    printf("%s\n", $traceparent);
}
