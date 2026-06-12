<?php

declare(strict_types=1);

namespace Arcp\Json;

use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageType;
use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Envelope\UnknownMessage;
use Arcp\Errors\InvalidRequestException;
use Arcp\Extensions\ExtensionRegistry;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SpanId;
use Arcp\Ids\StreamId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;
use Arcp\Internal\Json\EnvelopeMetadataCodec;

/**
 * JSON encode/decode for {@see Envelope} (RFC §6.1).
 *
 * Hand-rolled rather than driven by a general-purpose serializer: the
 * protocol surface is small enough and the polymorphism rules are
 * specific enough that a dedicated serializer is cleaner than reflection
 * config. {@see MessageTypeRegistry} owns type-name → class mapping.
 * Metadata array <-> object plumbing lives in
 * {@see EnvelopeMetadataCodec} so this class stays within the size cap.
 */
final readonly class EnvelopeSerializer
{
    private EnvelopeMetadataCodec $metadata;

    public function __construct(
        private MessageTypeRegistry $registry,
        private ?ExtensionRegistry $extensions = null,
    ) {
        $this->metadata = new EnvelopeMetadataCodec();
    }

    public function encode(Envelope $env): string
    {
        try {
            return json_encode(
                $this->envelopeToArray($env),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException $e) {
            throw new InvalidRequestException(
                'envelope encode failed: ' . $e->getMessage(),
                [],
                null,
                $e,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function envelopeToArray(Envelope $env): array
    {
        return [
            ...$this->metadata->envelopeToArray($env),
            'payload' => $env->payload->toArray(),
        ];
    }

    public function decode(string $json): Envelope
    {
        try {
            $data = json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidRequestException(
                'envelope decode failed: ' . $e->getMessage(),
                [],
                null,
                $e,
            );
        }
        if (!\is_array($data)) {
            throw new InvalidRequestException('envelope must decode to an object');
        }
        /** @var array<string, mixed> $data */
        return $this->envelopeFromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function envelopeFromArray(array $data): Envelope
    {
        $meta = $this->metadata;
        $type = $meta->requireString($data, 'type');
        $idStr = $meta->requireString($data, 'id');
        $arcp = $meta->requireString($data, 'arcp');
        $timestamp = $meta->parseTimestamp($meta->requireString($data, 'timestamp'));
        $payload = $this->decodePayload($type, $data);

        return new Envelope(
            id: new MessageId($idStr),
            payload: $payload,
            timestamp: $timestamp,
            priority: $meta->parsePriority($data),
            sessionId: $meta->optionalId($data, 'session_id', SessionId::class),
            jobId: $meta->optionalId($data, 'job_id', JobId::class),
            eventSeq: $meta->optionalInt($data, 'event_seq'),
            streamId: $meta->optionalId($data, 'stream_id', StreamId::class),
            subscriptionId: $meta->optionalId($data, 'subscription_id', SubscriptionId::class),
            traceId: $meta->optionalId($data, 'trace_id', TraceId::class),
            spanId: $meta->optionalId($data, 'span_id', SpanId::class),
            parentSpanId: $meta->optionalId($data, 'parent_span_id', SpanId::class),
            correlationId: $meta->optionalId($data, 'correlation_id', MessageId::class),
            causationId: $meta->optionalId($data, 'causation_id', MessageId::class),
            idempotencyKey: $meta->optionalId($data, 'idempotency_key', IdempotencyKey::class),
            source: $meta->optionalString($data, 'source'),
            target: $meta->optionalString($data, 'target'),
            arcp: $arcp,
            extensions: $meta->parseExtensions($data),
        );
    }

    /**
     * @param array<string, mixed> $envelopeData
     */
    private function decodePayload(string $type, array $envelopeData): MessageType
    {
        $payloadData = [];
        if (isset($envelopeData['payload'])) {
            if (!\is_array($envelopeData['payload'])) {
                throw new InvalidRequestException('envelope.payload must be object');
            }
            /** @var array<string, mixed> $payloadData */
            $payloadData = $envelopeData['payload'];
        }

        $class = $this->registry->classFor($type);
        if ($class === null) {
            return $this->unknownPayload($type, $payloadData, $envelopeData);
        }

        return $class::fromArray($payloadData);
    }

    /**
     * §5: unrecognized message types MUST be ignored, not rejected. Decode
     * them into an {@see UnknownMessage} marker the dispatch loops skip.
     * The one exception is a *mandatory* unadvertised extension type when
     * an extension registry is present (RFC §21.3 disposition `nack`).
     *
     * @param array<string, mixed> $payloadData
     * @param array<string, mixed> $envelopeData
     */
    private function unknownPayload(
        string $type,
        array $payloadData,
        array $envelopeData,
    ): UnknownMessage {
        if ($this->extensions instanceof ExtensionRegistry) {
            $optional = $this->isOptionalExtension($envelopeData);
            if ($this->extensions->dispositionFor($type, $optional) === 'nack') {
                throw new InvalidRequestException(
                    \sprintf('mandatory extension type %s not negotiated', $type),
                );
            }
        }
        return new UnknownMessage($type, $payloadData);
    }

    /**
     * @param array<string, mixed> $envelopeData
     */
    private function isOptionalExtension(array $envelopeData): bool
    {
        if (!isset($envelopeData['extensions']) || !\is_array($envelopeData['extensions'])) {
            return false;
        }
        return isset($envelopeData['extensions']['optional'])
            && $envelopeData['extensions']['optional'] === true;
    }
}
