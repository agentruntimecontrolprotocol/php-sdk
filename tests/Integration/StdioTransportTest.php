<?php

declare(strict_types=1);

namespace Arcp\Tests\Integration;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageCatalog;
use Arcp\Ids\MessageId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

/**
 * RFC §22 stdio transport. The newline-delimited-JSON contract is
 * tested over OS-level pipe pairs created with `\stream_socket_pair()`,
 * not real subprocesses — the encoding is what we want to verify here.
 */
final class StdioTransportTest extends TestCase
{
    public function testRoundTripOverPipePair(): void
    {
        // Two pipe pairs: A reads B's writes (and vice versa).
        $pair1 = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $pair2 = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair1);
        self::assertIsArray($pair2);

        $serializer = new EnvelopeSerializer(MessageCatalog::create());

        // Side A: read from pair1[0], write to pair2[0]
        // Side B: read from pair2[1], write to pair1[1]
        $a = new StdioTransport(
            new ReadableResourceStream($pair1[0]),
            new WritableResourceStream($pair2[0]),
            $serializer,
        );
        $b = new StdioTransport(
            new ReadableResourceStream($pair2[1]),
            new WritableResourceStream($pair1[1]),
            $serializer,
        );

        $env = new Envelope(
            id: new MessageId('msg_t'),
            payload: new EventEmit('demo', ['hello' => 'world']),
            timestamp: new \DateTimeImmutable('2026-05-09T12:00:00Z'),
        );
        $a->send($env);
        $received = $b->receive();
        self::assertNotNull($received);
        self::assertSame('msg_t', (string) $received->id);
        self::assertSame('event.emit', $received->type());

        $a->close();
        $b->close();
    }

    public function testFinalUnterminatedFrameIsDecoded(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        $serializer = new EnvelopeSerializer(MessageCatalog::create());

        $writer = new WritableResourceStream($pair[0]);
        $reader = new ReadableResourceStream($pair[1]);
        $transport = new StdioTransport($reader, new WritableResourceStream($pair[0]), $serializer);

        $env = new Envelope(
            id: new MessageId('msg_eof'),
            payload: new EventEmit('demo'),
            timestamp: new \DateTimeImmutable('2026-05-09T12:00:00Z'),
        );
        // Note: NO trailing newline.
        $writer->write($serializer->encode($env));
        $writer->end();

        $received = $transport->receive();
        self::assertNotNull($received);
        self::assertSame('msg_eof', (string) $received->id);
        self::assertNull($transport->receive());
    }

    public function testNewlineDelimitedFraming(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        $serializer = new EnvelopeSerializer(MessageCatalog::create());

        // Write three envelopes worth of JSON with explicit newlines, then
        // verify the reader yields three envelopes.
        $writer = new WritableResourceStream($pair[0]);
        $reader = new ReadableResourceStream($pair[1]);
        $transport = new StdioTransport($reader, new WritableResourceStream($pair[0]), $serializer);

        for ($i = 1; $i <= 3; ++$i) {
            $env = new Envelope(
                id: new MessageId('msg_' . $i),
                payload: new EventEmit('demo'),
                timestamp: new \DateTimeImmutable('2026-05-09T12:00:00Z'),
            );
            $writer->write($serializer->encode($env) . "\n");
        }
        $writer->end();

        $ids = [];
        while (($got = $transport->receive()) instanceof Envelope) {
            $ids[] = (string) $got->id;
        }
        self::assertSame(['msg_1', 'msg_2', 'msg_3'], $ids);
    }
}
