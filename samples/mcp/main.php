<?php

declare(strict_types=1);

/*
 * mcp — ARCP runtime fronting an MCP server (RFC §20).
 *
 * MCP describes capabilities; ARCP operationalizes them. This bridge
 * translates inbound ARCP `job.submit` envelopes into MCP `call_tool`
 * calls against an upstream MCP server, and emits the ARCP job
 * lifecycle back to the calling client.
 *
 *   ARCP client --job.submit--> bridge --call_tool--> MCP server
 *   ARCP client <-job.{accepted,started,completed,failed}- bridge
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/upstream.php';

use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\InternalErrorException;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobError;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Execution\JobResult;
use Arcp\Messages\Execution\JobSubmit;
use Arcp\Samples\Mcp\McpClientSession;

use function Arcp\Samples\Mcp\upstreamParams;

// Per RFC §20:
//   MCP tool schema -> ARCP capability  (advertised at session.welcome)
//   MCP tool call   -> ARCP job
//   MCP resource    -> ARCP stream of kind: event  (delegated to MCP)

/**
 * MCP `tools/list` -> namespaced ARCP capability extensions.
 *
 * Each upstream tool surfaces as `arcpx.mcp.tool.<name>.v1` so
 * clients can negotiate which tools they require at session open.
 *
 * @return list<string>
 */
function advertiseFromMcp(McpClientSession $mcp): array
{
    $tools = $mcp->listTools();
    return array_map(static fn (array $t) => "arcpx.mcp.tool.{$t['name']}.v1", $tools);
}

/**
 * Translate ARCP `job.submit.payload` into MCP `call_tool`.
 *
 * MCP returns a list of typed content blocks; we flatten to a
 * JSON-serializable array for the ARCP `job.result`
 * payload. MCP errors become canonical ARCP error codes.
 *
 * @param array<string, mixed> $arguments
 *
 * @return array<string, mixed>
 */
function callViaMcp(McpClientSession $mcp, string $tool, array $arguments): array
{
    try {
        $result = $mcp->callTool($tool, $arguments);
    } catch (\Throwable $exc) {
        throw new InternalErrorException($exc->getMessage(), previous: $exc);
    }

    if (($result['isError'] ?? false) === true) {
        $text = implode("\n", array_map(
            static fn (array $c): string => is_string($c['text'] ?? null) ? $c['text'] : '',
            $result['content'] ?? [],
        ));
        // MCP doesn't carry a typed error code; INVALID_REQUEST is the
        // closest §12 mapping for "tool ran, said no" (non-retryable).
        throw new InvalidRequestException($text !== '' ? $text : 'tool error');
    }

    return ['content' => $result['content'] ?? []];
}

/**
 * One inbound ARCP `job.submit` (§7.1) -> MCP call -> ARCP job lifecycle
 * (§7.3): job.accepted, a status job.event, then job.result / job.error.
 *
 * @param callable(Envelope): void $send
 */
function handleSubmit(callable $send, McpClientSession $mcp, Envelope $request): void
{
    $clock = new SystemClock();
    $jobId = JobId::random();

    $submit = $request->payload;
    if (!$submit instanceof JobSubmit) {
        throw new InternalErrorException('expected job.submit payload');
    }

    $send(new Envelope(
        id: MessageId::random(),
        payload: new JobAccepted(
            jobId: $jobId,
            agent: $submit->agent,
            acceptedAt: $clock->now(),
            traceId: $request->traceId,
        ),
        timestamp: $clock->now(),
        sessionId: $request->sessionId,
        jobId: $jobId,
        correlationId: $request->id,
    ));
    $send(new Envelope(
        id: MessageId::random(),
        payload: new JobEvent('status', $clock->now(), ['phase' => 'running']),
        timestamp: $clock->now(),
        sessionId: $request->sessionId,
        jobId: $jobId,
    ));

    try {
        $result = callViaMcp($mcp, $submit->agent, $submit->input);
    } catch (\Arcp\Errors\ARCPException $exc) {
        $send(new Envelope(
            id: MessageId::random(),
            payload: new JobError(JobError::ERROR, new \Arcp\Errors\ErrorPayload($exc->code()->value, $exc->getMessage())),
            timestamp: $clock->now(),
            sessionId: $request->sessionId,
            jobId: $jobId,
            correlationId: $request->id,
        ));
        return;
    }

    $send(new Envelope(
        id: MessageId::random(),
        payload: new JobResult(result: $result),
        timestamp: $clock->now(),
        sessionId: $request->sessionId,
        jobId: $jobId,
        correlationId: $request->id,
    ));
}

/**
 * Wire one MCP session as the upstream for one ARCP runtime.
 *
 * @param callable(Envelope): void $send
 * @param iterable<Envelope> $inbound
 */
function runBridge(callable $send, iterable $inbound): void
{
    $mcp = McpClientSession::stdio(upstreamParams());
    $mcp->initialize();
    $extensions = advertiseFromMcp($mcp);
    // In production this list would feed Capabilities.extensions at the
    // runtime's session.welcome so clients negotiate exactly the MCP
    // tools they expect to use.
    fwrite(STDERR, 'bridged: ' . implode(',', $extensions) . "\n");

    foreach ($inbound as $env) {
        if ($env->payload instanceof JobSubmit) {
            handleSubmit($send, $mcp, $env);
        }
    }
}

function main(): void
{
    // Production version: instantiate an Arcp\Runtime\ARCPRuntime, point
    // its job.submit handler at handleSubmit, and let the WebSocket
    // transport carry inbound envelopes from real ARCP clients. We
    // elide the runtime wiring (symmetric with examples in
    // Arcp\Runtime) so this file stays focused on the §20 translation
    // between protocols.
    $send = static fn (Envelope $e) => throw new \RuntimeException('not implemented');
    $inbound = (static function (): iterable {
        yield from [];
    })();
    runBridge($send, $inbound);
}

main();
