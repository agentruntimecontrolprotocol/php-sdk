<?php

declare(strict_types=1);

/**
 * @return list<array{type: string, seq: int, payload: array<string, mixed>}>
 */
function produceEvents(int $count): array
{
    $events = [];
    for ($seq = 1; $seq <= $count; $seq++) {
        $events[] = ['type' => 'job.progress', 'seq' => $seq, 'payload' => ['percent' => $seq * 10]];
    }

    return $events;
}

/**
 * @param list<array{type: string, seq: int, payload: array<string, mixed>}> $events
 *
 * @return list<array{type: string, after: int}>
 */
function acknowledgeWithBackpressure(array $events, int $window): array
{
    $acks = [];
    foreach ($events as $event) {
        if ($event['seq'] % $window === 0) {
            $acks[] = ['type' => 'session.ack', 'after' => $event['seq']];
        }
    }

    return $acks;
}

foreach (acknowledgeWithBackpressure(produceEvents(10), 3) as $ack) {
    printf("%s after=%d\n", $ack['type'], $ack['after']);
}
