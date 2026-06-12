<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Envelope\Priority;
use Arcp\Envelope\UnknownMessage;
use Arcp\Errors\InvalidRequestException;
use Arcp\Extensions\ExtensionRegistry;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SpanId;
use Arcp\Ids\TraceId;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Messages\Telemetry\EventEmit;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    private function newSerializer(?ExtensionRegistry $exts = null): EnvelopeSerializer
    {
        $registry = new MessageTypeRegistry();
        $registry->register(EventEmit::class);
        return new EnvelopeSerializer($registry, $exts);
    }

    private function fullEnvelope(): Envelope
    {
        return new Envelope(
            id: new MessageId('msg_01JABC'),
            payload: new EventEmit('subscription.backfill_complete', ['scanned' => 412]),
            timestamp: new \DateTimeImmutable('2026-05-09T13:00:00.000000+00:00'),
            priority: Priority::High,
            sessionId: new SessionId('sess_42'),
            traceId: new TraceId('trace_777'),
            spanId: new SpanId('span_aa'),
            parentSpanId: new SpanId('span_root'),
            correlationId: new MessageId('msg_subscribe_001'),
            causationId: new MessageId('msg_subscribe_001'),
            idempotencyKey: new IdempotencyKey('subscribe-once'),
            source: 'runtime/example-runtime',
            target: 'client/dashboard',
            extensions: ['arcpx.acme.tag' => 'ingest'],
        );
    }

    public function testRoundTripPreservesAllFields(): void
    {
        $env = $this->fullEnvelope();
        $serializer = $this->newSerializer();
        $json = $serializer->encode($env);
        $back = $serializer->decode($json);

        self::assertSame((string) $env->id, (string) $back->id);
        self::assertSame($env->type(), $back->type());
        self::assertSame($env->priority, $back->priority);
        self::assertSame((string) $env->sessionId, (string) $back->sessionId);
        self::assertSame((string) $env->traceId, (string) $back->traceId);
        self::assertSame((string) $env->spanId, (string) $back->spanId);
        self::assertSame((string) $env->parentSpanId, (string) $back->parentSpanId);
        self::assertSame((string) $env->correlationId, (string) $back->correlationId);
        self::assertSame((string) $env->causationId, (string) $back->causationId);
        self::assertSame((string) $env->idempotencyKey, (string) $back->idempotencyKey);
        self::assertSame($env->source, $back->source);
        self::assertSame($env->target, $back->target);
        self::assertSame($env->extensions, $back->extensions);
        self::assertEquals($env->payload, $back->payload);
        self::assertEquals(
            $env->timestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
            $back->timestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }

    public function testEncodeMatchesCanonicalFixture(): void
    {
        $env = $this->fullEnvelope();
        $serializer = $this->newSerializer();
        /** @var array<string, mixed> $encoded */
        $encoded = json_decode($serializer->encode($env), associative: true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/envelopes/event_emit_full.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertEquals($fixture, $encoded);
    }

    public function testDefaultPriorityNormalIsOmittedFromWire(): void
    {
        $env = new Envelope(
            id: new MessageId('msg_x'),
            payload: new EventEmit('demo'),
            timestamp: new \DateTimeImmutable('2026-05-09T13:00:00Z'),
        );
        $arr = $this->newSerializer()->envelopeToArray($env);
        self::assertArrayNotHasKey('priority', $arr);
    }

    public function testDecodeRejectsMissingType(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->newSerializer()->decode('{"arcp":"1.1","id":"a","timestamp":"2026-05-09T13:00:00Z","payload":{}}');
    }

    public function testDecodeUnknownTypeYieldsUnknownMessageMarker(): void
    {
        // §5: unrecognized message types are ignored, not rejected (#133).
        $serializer = $this->newSerializer();
        $env = $serializer->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => 'msg_x',
            'type' => 'totally.unknown',
            'timestamp' => '2026-05-09T13:00:00Z',
            'payload' => ['some' => 'field'],
        ]));
        self::assertInstanceOf(UnknownMessage::class, $env->payload);
        self::assertSame('totally.unknown', $env->type());
        self::assertSame(['some' => 'field'], $env->payload->payload);

        // Re-encoding preserves the original wire type and payload.
        /** @var array<string, mixed> $again */
        $again = json_decode($serializer->encode($env), associative: true);
        self::assertSame('totally.unknown', $again['type']);
        self::assertSame(['some' => 'field'], $again['payload']);
    }

    public function testDecodeIgnoresUnknownTopLevelEnvelopeFields(): void
    {
        // §5: unknown top-level envelope fields MUST be ignored.
        $env = $this->newSerializer()->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => 'msg_x',
            'type' => 'event.emit',
            'timestamp' => '2026-05-09T13:00:00Z',
            'payload' => ['type' => 'demo'],
            'a_future_field' => ['nested' => true],
        ]));
        self::assertSame('event.emit', $env->type());
    }

    public function testEventSeqRoundTrips(): void
    {
        // §5/§8.3: event_seq is a session-scoped monotonically increasing
        // sequence stamped on sequenced messages (#132, #152).
        $serializer = $this->newSerializer();
        $env = new Envelope(
            id: new MessageId('msg_seq'),
            payload: new EventEmit('demo'),
            timestamp: new \DateTimeImmutable('2026-05-09T13:00:00Z'),
            eventSeq: 1827,
        );
        /** @var array<string, mixed> $arr */
        $arr = json_decode($serializer->encode($env), associative: true);
        self::assertSame(1827, $arr['event_seq']);

        $back = $serializer->decode($serializer->encode($env));
        self::assertSame(1827, $back->eventSeq);
    }

    public function testEventSeqOmittedWhenAbsent(): void
    {
        $env = new Envelope(
            id: new MessageId('msg_x'),
            payload: new EventEmit('demo'),
            timestamp: new \DateTimeImmutable('2026-05-09T13:00:00Z'),
        );
        $arr = $this->newSerializer()->envelopeToArray($env);
        self::assertArrayNotHasKey('event_seq', $arr);
        self::assertNull($this->newSerializer()->decode(
            $this->newSerializer()->encode($env),
        )->eventSeq);
    }

    public function testEnvelopeRejectsNegativeEventSeq(): void
    {
        $this->expectException(InvalidRequestException::class);
        new Envelope(
            id: new MessageId('msg_x'),
            payload: new EventEmit('demo'),
            timestamp: new \DateTimeImmutable('now'),
            eventSeq: -1,
        );
    }

    public function testDecodeRejectsNonIntEventSeq(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->newSerializer()->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => 'msg_x',
            'type' => 'event.emit',
            'timestamp' => '2026-05-09T13:00:00Z',
            'event_seq' => 'twelve',
            'payload' => ['type' => 'demo'],
        ]));
    }

    public function testDecodeRejectsMalformedJson(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->newSerializer()->decode('not json');
    }

    public function testDecodeRejectsBadTimestamp(): void
    {
        $serializer = $this->newSerializer();
        $this->expectException(InvalidRequestException::class);
        $serializer->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => 'msg_x',
            'type' => 'event.emit',
            'timestamp' => 'definitely-not-rfc3339',
            'payload' => ['type' => 'demo'],
        ]));
    }

    public function testWithCorrelationIdReturnsCopy(): void
    {
        $env = $this->fullEnvelope();
        $newCorr = new MessageId('msg_other');
        $copy = $env->withCorrelationId($newCorr);
        self::assertNotSame($env, $copy);
        self::assertSame((string) $newCorr, (string) $copy->correlationId);
        self::assertSame((string) $env->correlationId, 'msg_subscribe_001');
    }

    public function testWithCorrelationIdToNull(): void
    {
        $env = $this->fullEnvelope();
        $copy = $env->withCorrelationId(null);
        self::assertNull($copy->correlationId);
    }

    public function testEnvelopeRejectsBlankSource(): void
    {
        $this->expectException(InvalidRequestException::class);
        new Envelope(
            id: new MessageId('msg_x'),
            payload: new EventEmit('demo'),
            timestamp: new \DateTimeImmutable('now'),
            source: '   ',
        );
    }

    public function testEnvelopeRejectsBlankTarget(): void
    {
        $this->expectException(InvalidRequestException::class);
        new Envelope(
            id: new MessageId('msg_x'),
            payload: new EventEmit('demo'),
            timestamp: new \DateTimeImmutable('now'),
            target: '   ',
        );
    }

    public function testEnvelopeRejectsBlankArcpVersion(): void
    {
        $this->expectException(InvalidRequestException::class);
        new Envelope(
            id: new MessageId('msg_x'),
            payload: new EventEmit('demo'),
            timestamp: new \DateTimeImmutable('now'),
            arcp: '',
        );
    }

    public function testDecodeRejectsNonObjectJson(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->newSerializer()->decode('"a string"');
    }

    public function testDecodeRejectsBlankRequiredFields(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->newSerializer()->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => '',
            'type' => 'event.emit',
            'timestamp' => '2026-05-09T13:00:00Z',
            'payload' => ['type' => 'demo'],
        ]));
    }

    public function testDecodeRejectsBadPriority(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->newSerializer()->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => 'msg_x',
            'type' => 'event.emit',
            'timestamp' => '2026-05-09T13:00:00Z',
            'priority' => 'urgent',
            'payload' => ['type' => 'demo'],
        ]));
    }

    public function testDecodeRejectsNonObjectPayload(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->newSerializer()->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => 'msg_x',
            'type' => 'event.emit',
            'timestamp' => '2026-05-09T13:00:00Z',
            'payload' => 'not-an-object',
        ]));
    }

    public function testExtensionAdvertisedButUnregisteredYieldsUnknownMarker(): void
    {
        // Advertised extension with no registered class decodes to the
        // ignorable marker (§5 leniency) rather than throwing.
        $exts = new ExtensionRegistry(['arcpx.acme.v1']);
        $serializer = $this->newSerializer($exts);

        $env = $serializer->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => 'msg_x',
            'type' => 'arcpx.acme.v1',
            'timestamp' => '2026-05-09T13:00:00Z',
            'payload' => [],
        ]));
        self::assertInstanceOf(UnknownMessage::class, $env->payload);
        self::assertSame('arcpx.acme.v1', $env->type());
    }

    public function testExtensionOptionalUnadvertisedDropsViaUnknownMarker(): void
    {
        // RFC §21.3: an optional unadvertised extension is dropped silently.
        $exts = new ExtensionRegistry();
        $serializer = $this->newSerializer($exts);

        $env = $serializer->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => 'msg_x',
            'type' => 'arcpx.unknown.v1',
            'timestamp' => '2026-05-09T13:00:00Z',
            'payload' => [],
            'extensions' => ['optional' => true],
        ]));
        self::assertInstanceOf(UnknownMessage::class, $env->payload);
    }

    public function testExtensionMandatoryUnadvertisedIsRejected(): void
    {
        // RFC §21.3 disposition `nack`: a mandatory unadvertised extension
        // still raises so the dispatcher can nack INVALID_REQUEST.
        $exts = new ExtensionRegistry();
        $serializer = $this->newSerializer($exts);

        $this->expectException(InvalidRequestException::class);
        $serializer->decode((string) json_encode([
            'arcp' => '1.1',
            'id' => 'msg_x',
            'type' => 'arcpx.unknown.v1',
            'timestamp' => '2026-05-09T13:00:00Z',
            'payload' => [],
        ]));
    }
}
