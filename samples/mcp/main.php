<?php

declare(strict_types=1);

/*
 * mcp — ARCP runtime fronting an MCP server (RFC §20).
 *
 * MCP describes capabilities; ARCP operationalizes them. This bridge
 * translates inbound ARCP `tool.invoke` envelopes into MCP `call_tool`
 * calls against an upstream MCP server, and emits the ARCP job
 * lifecycle back to the calling client.
 *
 *   ARCP client --tool.invoke--> bridge --call_tool--> MCP server
 *   ARCP client <-job.{accepted,started,completed,failed}- bridge
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/upstream.php';

use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\FailedPreconditionException;
use Arcp\Errors\InternalException;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobCompleted;
use Arcp\Messages\Execution\JobFailed;
use Arcp\Messages\Execution\JobStarted;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Samples\Mcp\McpClientSession;

use function Arcp\Samples\Mcp\upstreamParams;

// Per RFC §20:
//   MCP tool schema -> ARCP capability  (advertised at session.accepted)
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
 * Translate ARCP `tool.invoke.payload` into MCP `call_tool`.
 *
 * MCP returns a list of typed content blocks; we flatten to a
 * JSON-serializable array for the ARCP `tool.result` / `job.completed`
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
        throw new InternalException($exc->getMessage(), previous: $exc);
    }

    if (($result['isError'] ?? false) === true) {
        $text = implode("\n", array_map(
            static fn (array $c): string => is_string($c['text'] ?? null) ? $c['text'] : '',
            $result['content'] ?? [],
        ));
        // MCP doesn't carry a typed error code; FAILED_PRECONDITION is
        // the right canonical mapping for "tool ran, said no".
        throw new FailedPreconditionException($text !== '' ? $text : 'tool error');
    }

    return ['content' => $result['content'] ?? []];
}

/**
 * One inbound ARCP `tool.invoke` -> MCP call -> ARCP job lifecycle.
 *
 * @param callable(Envelope): void $send
 */
function handleInvoke(callable $send, McpClientSession $mcp, Envelope $request): void
{
    $clock = new SystemClock();
    $jobId = JobId::random();

    $send(new Envelope(
        id: MessageId::random(),
        payload: new JobAccepted(note: 'accepted'),
        timestamp: $clock->now(),
        sessionId: $request->sessionId,
        jobId: $jobId,
        correlationId: $request->id,
    ));
    $send(new Envelope(
        id: MessageId::random(),
        payload: new JobStarted(startedAt: $clock->now()),
        timestamp: $clock->now(),
        sessionId: $request->sessionId,
        jobId: $jobId,
    ));

    $invoke = $request->payload;
    if (!$invoke instanceof ToolInvoke) {
        throw new InternalException('expected tool.invoke payload');
    }

    try {
        $result = callViaMcp($mcp, $invoke->tool, $invoke->arguments);
    } catch (\Arcp\Errors\ARCPException $exc) {
        $send(new Envelope(
            id: MessageId::random(),
            payload: new JobFailed(new \Arcp\Errors\ErrorPayload($exc->code()->value, $exc->getMessage())),
            timestamp: $clock->now(),
            sessionId: $request->sessionId,
            jobId: $jobId,
        ));
        return;
    }

    $send(new Envelope(
        id: MessageId::random(),
        payload: new JobCompleted(value: $result),
        timestamp: $clock->now(),
        sessionId: $request->sessionId,
        jobId: $jobId,
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
    // runtime's session.accepted so clients negotiate exactly the MCP
    // tools they expect to use.
    fwrite(STDERR, 'bridged: ' . implode(',', $extensions) . "\n");

    foreach ($inbound as $env) {
        if ($env->payload instanceof ToolInvoke) {
            handleInvoke($send, $mcp, $env);
        }
    }
}

function main(): void
{
    // Production version: instantiate an Arcp\Runtime\ARCPRuntime, point
    // its tool-invoke handler at handleInvoke, and let the WebSocket
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
