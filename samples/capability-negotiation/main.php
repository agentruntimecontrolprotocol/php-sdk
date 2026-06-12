<?php

declare(strict_types=1);

/*
 * capability-negotiation — capability-driven peer routing with ordered
 * fallback + cost rollups via the standard `cost.usd` metric.
 *
 * RFC §7 (capability negotiation), §17.3.1 (standard metrics), §18.3
 * (canonical retry classification), §21 (extensions).
 */

require __DIR__ . '/../../vendor/autoload.php';

use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorCode;
use Arcp\Errors\InternalErrorException;
use Arcp\Ids\TraceId;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Execution\JobResult;

const PEERS = [
    'anthropic-haiku',
    'anthropic-sonnet',
    'openai-4o',
    'groq-llama',
];
const FALLBACK_CHAINS = [
    'cheap_fast' => ['groq-llama', 'anthropic-haiku', 'openai-4o'],
    'balanced'   => ['anthropic-sonnet', 'openai-4o', 'anthropic-haiku'],
    'deep'       => ['anthropic-sonnet'],
];
const COST_CEILING_USD_PER_MTOK = 8.0;
const LATENCY_CEILING_MS = 800;

function isRetryable(ErrorCode $code): bool
{
    // §12: TIMEOUT, HEARTBEAT_LOST, and INTERNAL_ERROR are the
    // retryable-by-default codes.
    return $code->defaultRetryable();
}

final class Profile
{
    public function __construct(
        public readonly float $costPerMtok,
        public readonly int $p50LatencyMs,
        public readonly string $modelClass,
    ) {
    }
}

function profileFrom(ARCPClient $client): Profile
{
    // Capabilities allows extra namespaced keys via `extra`.
    // NOTE: §21 covers extension *messages* but not extension
    // *capability values* — load-bearing convention here.
    $caps = $client->session->capabilities;
    $extra = $caps !== null ? $caps->extra : [];
    return new Profile(
        costPerMtok: asFloat($extra['arcpx.market.cost_per_mtok.v1'] ?? 0.0),
        p50LatencyMs: asInt($extra['arcpx.market.p50_latency_ms.v1'] ?? 0),
        modelClass: asString($extra['arcpx.market.model_class.v1'] ?? 'unknown'),
    );
}

function asFloat(mixed $v): float
{
    return is_int($v) || is_float($v) || is_string($v) ? (float) $v : 0.0;
}

function asInt(mixed $v): int
{
    return is_int($v) || is_float($v) || is_string($v) ? (int) $v : 0;
}

function asString(mixed $v): string
{
    return is_string($v) ? $v : (is_scalar($v) ? (string) $v : 'unknown');
}

/**
 * @param array<string, Profile> $profiles
 *
 * @return list<string>
 */
function candidateChain(array $profiles, string $requestClass): array
{
    $out = [];
    foreach (FALLBACK_CHAINS[$requestClass] ?? [] as $name) {
        $p = $profiles[$name] ?? null;
        if ($p === null) {
            continue;
        }
        if ($p->costPerMtok > COST_CEILING_USD_PER_MTOK) {
            continue;
        }
        if ($p->p50LatencyMs > LATENCY_CEILING_MS) {
            continue;
        }
        $out[] = $name;
    }
    return $out;
}

/**
 * Walk the chain. Retryable error → next peer; otherwise rethrow.
 *
 * @param array<string, ARCPClient> $clients
 * @param list<string> $chain
 * @param array<string, mixed> $arguments
 */
function invokeWithFallback(array $clients, array $chain, string $tool, array $arguments, TraceId $traceId): JobResult
{
    $last = null;
    foreach ($chain as $name) {
        if (!isset($clients[$name])) {
            continue;
        }
        $client = $clients[$name];
        try {
            return $client->invokeTool($tool, $arguments, traceId: $traceId);
        } catch (ARCPException $exc) {
            $last = $exc;
            if (isRetryable($exc->code())) {
                continue;
            }
            throw $exc;
        }
    }
    throw $last ?? new InternalErrorException('no peers available');
}

final class Usage
{
    public int $tokensIn = 0;
    public int $tokensOut = 0;
    public float $costUsd = 0.0;
    /** @var array<string, float> */
    public array $byPeer = [];
}

/**
 * @param array<string, Usage> $totals
 */
function consumeMetric(Envelope $env, array &$totals): void
{
    $msg = $env->payload;
    // §8.2: metrics ride as job.event kind "metric" with body
    // {name, value, unit?, dimensions?}.
    if (!$msg instanceof JobEvent || $msg->eventKind !== 'metric') {
        return;
    }
    $body = $msg->body;
    $name = asString($body['name'] ?? '');
    $value = $body['value'] ?? 0;
    $value = is_int($value) || is_float($value) ? (float) $value : 0.0;
    $dims = is_array($body['dimensions'] ?? null) ? $body['dimensions'] : [];
    $tenant = asString($dims['tenant'] ?? 'unknown');
    $totals[$tenant] ??= new Usage();
    $u = $totals[$tenant];
    if ($name === 'tokens.used') {
        $kind = asString($dims['kind'] ?? '');
        if ($kind === 'input') {
            $u->tokensIn += (int) $value;
        } elseif ($kind === 'output') {
            $u->tokensOut += (int) $value;
        }
    } elseif ($name === 'cost.usd') {
        $u->costUsd += $value;
        $peer = asString($dims['peer'] ?? 'unknown');
        $u->byPeer[$peer] = ($u->byPeer[$peer] ?? 0.0) + $value;
    }
}

function main(): void
{
    /** @var array<string, ARCPClient> $clients */
    $clients = [];
    /** @var array<string, Profile> $profiles */
    $profiles = [];
    foreach (PEERS as $name) {
        $c = elided(); // transport per peer URL, identity, auth elided
        $clients[$name] = $c;
        // Marketplace fields ride on the negotiated capabilities;
        // no extra round trip to learn cost / latency / class.
        $profiles[$name] = profileFrom($c);
    }

    /** @var array<string, Usage> $totals */
    $totals = [];
    // Real impl: after job.accepted, attach to each peer's job with
    // $c->subscribe($jobId, ...) (§7.6) and feed metric envelopes into
    // consumeMetric() from those per-job streams.

    $chain = candidateChain($profiles, 'balanced');
    $reply = invokeWithFallback(
        $clients,
        $chain,
        'chat.completion',
        ['prompt' => 'Hello', 'tenant' => 'acme-corp'],
        TraceId::random(),
    );
    printf("got job.result; usage=%s\n", json_encode($totals));

    foreach ($clients as $c) {
        $c->close();
    }
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
