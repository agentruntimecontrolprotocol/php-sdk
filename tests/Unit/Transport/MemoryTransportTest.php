<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Transport;

use function Amp\Future\await;

use Arcp\Envelope\Envelope;
use Arcp\Ids\MessageId;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class MemoryTransportTest extends TestCase
{
    private function env(int $seq): Envelope
    {
        return new Envelope(
            id: MessageId::random(),
            payload: new EventEmit('demo', ['seq' => $seq]),
            timestamp: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    private function seqOf(?Envelope $env): int
    {
        self::assertInstanceOf(Envelope::class, $env);
        $payload = $env->payload;
        self::assertInstanceOf(EventEmit::class, $payload);
        $seq = $payload->attributes['seq'];
        self::assertIsInt($seq);

        return $seq;
    }

    public function testManyPendingReceiversGetEnvelopesInFifoOrder(): void
    {
        [$a, $b] = MemoryTransport::pair();

        $futures = [];
        for ($i = 0; $i < 64; $i++) {
            $futures[] = $b->receiveAsync();
        }
        for ($i = 0; $i < 64; $i++) {
            $a->send($this->env($i));
        }

        $results = await($futures);
        foreach ($results as $i => $env) {
            self::assertInstanceOf(Envelope::class, $env);
            self::assertSame($i, $this->seqOf($env));
        }
    }

    public function testInboxPreservesFifoWhenNoWaiters(): void
    {
        [$a, $b] = MemoryTransport::pair();
        $a->send($this->env(0));
        $a->send($this->env(1));
        $a->send($this->env(2));

        self::assertSame(0, $this->seqOf($b->receive()));
        self::assertSame(1, $this->seqOf($b->receive()));
        self::assertSame(2, $this->seqOf($b->receive()));
    }

    public function testCloseDeliversEofToPendingReceiver(): void
    {
        [$a, $b] = MemoryTransport::pair();
        $future = $b->receiveAsync();
        $a->close();
        self::assertNull($future->await());
    }
}
