<?php

declare(strict_types=1);

namespace Arcp\Messages\Telemetry;

/**
 * Reserved metric names per RFC §17.3.1. Implementations producing these
 * concepts MUST use these names with the indicated units; non-standard
 * variants MUST be namespaced (e.g. `arcpx.acme.cache_hit_ratio`).
 */
final class StandardMetrics
{
    public const string TOKENS_USED = 'tokens.used';
    public const string COST_USD = 'cost.usd';
    public const string GPU_SECONDS = 'gpu.seconds';
    public const string TOOL_INVOCATIONS = 'tool.invocations';
    public const string LATENCY_MS = 'latency.ms';
    public const string BYTES_IN = 'bytes.in';
    public const string BYTES_OUT = 'bytes.out';
    public const string ERRORS_TOTAL = 'errors.total';

    public const string UNIT_TOKENS = 'tokens';
    public const string UNIT_USD = 'usd';
    public const string UNIT_SECONDS = 'seconds';
    public const string UNIT_COUNT = 'count';
    public const string UNIT_MS = 'ms';
    public const string UNIT_BYTES = 'bytes';
}
