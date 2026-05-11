<?php

declare(strict_types=1);

/*
 * human_input — fan `human.input.request` across phone / email / Slack;
 * resolve on the first valid response, cancel the rest.
 *
 * RFC §12.1, §12.3, §12.4.
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/channels.php';

use function Amp\async;

use Arcp\Client\ARCPClient;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Ids\MessageId;
use Arcp\Messages\Human\HumanInputCancelled;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;
use Arcp\Samples\HumanInput\ChannelRegistry;

const DESTINATIONS = ['ntfy:phone', 'email:oncall', 'slack:ops'];

function fanOut(ARCPClient $client, Envelope $request): void
{
    $msg = $request->payload;
    if (!$msg instanceof HumanInputRequest) {
        return;
    }
    $expiresAt = $msg->expiresAt;
    $clock = new SystemClock();
    $timeout = max(0.0, (float) ($expiresAt->getTimestamp() - $clock->now()->getTimestamp()));

    $registry = ChannelRegistry::default();
    $futures = [];
    foreach (DESTINATIONS as $dest) {
        $futures[$dest] = async(static fn () => $registry->ask($dest, $msg->prompt, $msg->responseSchema));
    }

    try {
        // First-wins. Real impl uses Amp\Future::awaitAny with a timeout
        // cancellation. Captures (dest, value).
        [$winnerDest, $value] = awaitFirstWithDest($futures, $timeout);
    } catch (\Throwable) {
        // Deadline elapsed; translate timeout into the cancelled-input
        // shape (RFC §12.4).
        $client->session->transport->send(new Envelope(
            id: MessageId::random(),
            payload: new HumanInputCancelled(code: 'DEADLINE_EXCEEDED', reason: 'no channel responded before expires_at'),
            timestamp: $clock->now(),
            sessionId: $client->session->sessionId,
            correlationId: $request->id,
        ));
        return;
    }

    $client->session->transport->send(new Envelope(
        id: MessageId::random(),
        payload: new HumanInputResponse(
            value: $value,
            respondedBy: $winnerDest,
            respondedAt: $clock->now(),
        ),
        timestamp: $clock->now(),
        sessionId: $client->session->sessionId,
        correlationId: $request->id,
    ));

    // Tell the losing destinations the question is settled. Each
    // channel adapter would translate this to "delete the push" /
    // "edit the slack message to '(answered)'".
    $losers = array_values(array_filter(DESTINATIONS, static fn ($d) => $d !== $winnerDest));
    if ($losers !== []) {
        $client->session->transport->send(new Envelope(
            id: MessageId::random(),
            payload: new HumanInputCancelled(code: 'OK', reason: 'answered elsewhere by ' . $winnerDest),
            timestamp: $clock->now(),
            sessionId: $client->session->sessionId,
            correlationId: $request->id,
        ));
    }
}

/**
 * @param array<string, \Amp\Future<mixed>> $futures
 *
 * @return array{0: string, 1: mixed}
 */
function awaitFirstWithDest(array $futures, float $timeout): array
{
    // Real impl: race futures with a TimeoutCancellation; return the
    // first (key, value) pair that resolves.
    throw new \RuntimeException('not implemented');
}

function main(): void
{
    /** @var ARCPClient $client */
    $client = elided(); // transport, identity, auth elided
    // Drain inbound human.input.request envelopes; dispatch each
    // to fan-out. Real impl uses subscribe(['types'=>[...]], ...).
    $client->subscribe(
        ['types' => ['human.input.request']],
        static function (Envelope $env) use ($client): void {
            async(static fn () => fanOut($client, $env));
        },
    );
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
