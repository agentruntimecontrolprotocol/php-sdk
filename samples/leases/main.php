<?php

declare(strict_types=1);

/*
 * leases — sandboxed on-call agent. Lease-gated shell, reasoning streamed.
 *
 * RFC §15.4 (permission challenge), §15.5 (lease lifecycle), §11.4
 * (kind: thought streams).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/agent.php';

use Arcp\Client\ARCPClient;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorCode;
use Arcp\Errors\PermissionDeniedException;
use Arcp\Ids\MessageId;
use Arcp\Ids\StreamId;
use Arcp\Messages\Streaming\StreamChunk;
use Arcp\Messages\Streaming\StreamKind;
use Arcp\Messages\Streaming\StreamOpen;

use function Arcp\Samples\Leases\llmLoop;

use Arcp\Samples\Leases\LLMStep;

const READ_BINARIES = ['/usr/bin/journalctl', '/usr/bin/cat', '/usr/bin/ss', '/usr/bin/ps'];
const WRITE_BINARIES = ['/usr/bin/systemctl', '/usr/bin/kill'];
const READ_LEASE_SECONDS = 30 * 60;
const WRITE_LEASE_SECONDS = 60;

/**
 * @param list<string> $argv
 *
 * @return array{0: string, 1: string, 2: string, 3: int}
 */
function classify(array $argv, string $host): array
{
    if ($argv === []) {
        throw new PermissionDeniedException('binary', '?', 'empty argv');
    }
    $binary = $argv[0];
    if (in_array($binary, READ_BINARIES, true)) {
        return ['host.read', "host:{$host}", 'read', READ_LEASE_SECONDS];
    }
    if (in_array($binary, WRITE_BINARIES, true)) {
        $target = $binary === '/usr/bin/systemctl' ? ($argv[2] ?? '?') : ($argv[1] ?? '?');
        return ['host.write', "host:{$host}/{$binary}/{$target}", 'write', WRITE_LEASE_SECONDS];
    }
    throw new PermissionDeniedException('binary', $binary, "binary not allowed: {$binary}");
}

function acquireLease(
    ARCPClient $client,
    string $permission,
    string $resource,
    string $operation,
    int $seconds,
    string $reason,
): string {
    // Sends `permission.request`; awaits `lease.granted` / `permission.deny`.
    // Implemented in production via $client->requestPermission(...).
    throw new \RuntimeException('not implemented');
}

/**
 * @param list<string> $argv
 */
function runCommand(ARCPClient $client, array $argv, string $reason, string $host): string
{
    [$permission, $resource, $operation, $seconds] = classify($argv, $host);
    $lease = acquireLease($client, $permission, $resource, $operation, $seconds, $reason);
    // The lease is the only guard. Spawn the subprocess elsewhere.
    return '<would run ' . implode(' ', $argv) . " under lease {$lease}>";
}

function emitThought(ARCPClient $client, StreamId $streamId, int $sequence, string $text): void
{
    $env = new Envelope(
        id: MessageId::random(),
        payload: new StreamChunk(sequence: $sequence, role: 'assistant_thought', content: $text),
        timestamp: new SystemClock()->now(),
        sessionId: $client->session->sessionId,
        streamId: $streamId,
    );
    $client->session->transport->send($env);
}

function main(): void
{
    /** @var ARCPClient $client */
    $client = elided(); // transport, identity (constrained), auth elided

    $streamId = StreamId::random();
    $client->session->transport->send(new Envelope(
        id: MessageId::random(),
        payload: new StreamOpen(StreamKind::Thought),
        timestamp: new SystemClock()->now(),
        sessionId: $client->session->sessionId,
        streamId: $streamId,
    ));

    $seq = 0;
    foreach (llmLoop('api-gateway pod is OOMing every 4 minutes') as $step) {
        /** @var LLMStep $step */
        emitThought($client, $streamId, $seq++, $step->thought);
        if ($step->toolCall !== null) {
            try {
                runCommand($client, $step->toolCall->argv, $step->toolCall->reason, 'edge-pod-04');
            } catch (ARCPException $e) {
                if ($e->code() === ErrorCode::PermissionDenied) {
                    continue; // feeds back into the next prompt
                }
                throw $e;
            }
        }
        if ($step->final !== null) {
            echo $step->final, "\n";
            break;
        }
    }

    $client->close();
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
