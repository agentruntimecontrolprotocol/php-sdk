<?php

declare(strict_types=1);

namespace Arcp\Json;

use Arcp\Envelope\Envelope;
use Arcp\Envelope\MessageType;
use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Envelope\Priority;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\UnimplementedException;
use Arcp\Extensions\ExtensionRegistry;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SpanId;
use Arcp\Ids\StreamId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;

/**
 * JSON encode/decode for {@see Envelope} (RFC §6.1).
 *
 * Hand-rolled rather than driven by a general-purpose serializer: the
 * protocol surface is small enough and the polymorphism rules are
 * specific enough that a dedicated serializer is cleaner than reflection
 * config. {@see MessageTypeRegistry} owns type-name → class mapping.
 */
final class EnvelopeSerializer
{
    public function __construct(
        private readonly MessageTypeRegistry $registry,
        private readonly ?ExtensionRegistry $extensions = null,
    ) {
    }

    public function encode(Envelope $env): string
    {
        try {
            return json_encode($this->envelopeToArray($env), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('envelope encode failed: ' . $e->getMessage(), [], null, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function envelopeToArray(Envelope $env): array
    {
        $out = [
            'arcp' => $env->arcp,
            'id' => (string) $env->id,
            'type' => $env->type(),
            'timestamp' => $env->timestamp->format('Y-m-d\\TH:i:s.up'),
            'payload' => $env->payload->toArray(),
        ];

        if ($env->priority !== Priority::Normal) {
            $out['priority'] = $env->priority->value;
        }
        if ($env->sessionId !== null) {
            $out['session_id'] = (string) $env->sessionId;
        }
        if ($env->jobId !== null) {
            $out['job_id'] = (string) $env->jobId;
        }
        if ($env->streamId !== null) {
            $out['stream_id'] = (string) $env->streamId;
        }
        if ($env->subscriptionId !== null) {
            $out['subscription_id'] = (string) $env->subscriptionId;
        }
        if ($env->traceId !== null) {
            $out['trace_id'] = (string) $env->traceId;
        }
        if ($env->spanId !== null) {
            $out['span_id'] = (string) $env->spanId;
        }
        if ($env->parentSpanId !== null) {
            $out['parent_span_id'] = (string) $env->parentSpanId;
        }
        if ($env->correlationId !== null) {
            $out['correlation_id'] = (string) $env->correlationId;
        }
        if ($env->causationId !== null) {
            $out['causation_id'] = (string) $env->causationId;
        }
        if ($env->idempotencyKey !== null) {
            $out['idempotency_key'] = (string) $env->idempotencyKey;
        }
        if ($env->source !== null) {
            $out['source'] = $env->source;
        }
        if ($env->target !== null) {
            $out['target'] = $env->target;
        }
        if ($env->extensions !== []) {
            $out['extensions'] = $env->extensions;
        }

        return $out;
    }

    public function decode(string $json): Envelope
    {
        try {
            $data = json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('envelope decode failed: ' . $e->getMessage(), [], null, $e);
        }
        if (!\is_array($data)) {
            throw new InvalidArgumentException('envelope must decode to an object');
        }
        /** @var array<string, mixed> $data */
        return $this->envelopeFromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function envelopeFromArray(array $data): Envelope
    {
        $type = $this->requireString($data, 'type');
        $idStr = $this->requireString($data, 'id');
        $arcp = $this->requireString($data, 'arcp');
        $tsStr = $this->requireString($data, 'timestamp');

        try {
            $timestamp = new \DateTimeImmutable($tsStr);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                'envelope.timestamp must be RFC 3339',
                ['timestamp' => $tsStr],
                null,
                $e,
            );
        }

        $payload = $this->decodePayload($type, $data);

        $priority = Priority::Normal;
        if (isset($data['priority'])) {
            if (!\is_string($data['priority']) || Priority::tryFrom($data['priority']) === null) {
                throw new InvalidArgumentException(
                    'envelope.priority not recognized',
                    ['priority' => $data['priority']],
                );
            }
            $priority = Priority::from($data['priority']);
        }

        $extensions = [];
        if (isset($data['extensions'])) {
            if (!\is_array($data['extensions'])) {
                throw new InvalidArgumentException('envelope.extensions must be object');
            }
            /** @var array<string, mixed> $extensions */
            $extensions = $data['extensions'];
        }

        return new Envelope(
            id: new MessageId($idStr),
            payload: $payload,
            timestamp: $timestamp,
            priority: $priority,
            sessionId: $this->optionalId($data, 'session_id', SessionId::class),
            jobId: $this->optionalId($data, 'job_id', JobId::class),
            streamId: $this->optionalId($data, 'stream_id', StreamId::class),
            subscriptionId: $this->optionalId($data, 'subscription_id', SubscriptionId::class),
            traceId: $this->optionalId($data, 'trace_id', TraceId::class),
            spanId: $this->optionalId($data, 'span_id', SpanId::class),
            parentSpanId: $this->optionalId($data, 'parent_span_id', SpanId::class),
            correlationId: $this->optionalId($data, 'correlation_id', MessageId::class),
            causationId: $this->optionalId($data, 'causation_id', MessageId::class),
            idempotencyKey: $this->optionalId($data, 'idempotency_key', IdempotencyKey::class),
            source: $this->optionalString($data, 'source'),
            target: $this->optionalString($data, 'target'),
            arcp: $arcp,
            extensions: $extensions,
        );
    }

    /**
     * @param array<string, mixed> $envelopeData
     */
    private function decodePayload(string $type, array $envelopeData): MessageType
    {
        $class = $this->registry->classFor($type);
        if ($class === null) {
            // Honor extension dispatch rules (RFC §21.3) when an extension
            // registry is present. Without one, default to UNIMPLEMENTED.
            if ($this->extensions !== null) {
                $optional = $this->isOptionalExtension($envelopeData);
                $disposition = $this->extensions->dispositionFor($type, $optional);
                if ($disposition === 'drop' || $disposition === 'advertised') {
                    throw new UnimplementedException(
                        '§21',
                        \sprintf('extension type %s has no registered class', $type),
                    );
                }
            }
            throw new UnimplementedException('§6.2', \sprintf('unknown message type %s', $type));
        }

        $payloadData = [];
        if (isset($envelopeData['payload'])) {
            if (!\is_array($envelopeData['payload'])) {
                throw new InvalidArgumentException('envelope.payload must be object');
            }
            /** @var array<string, mixed> $payloadData */
            $payloadData = $envelopeData['payload'];
        }

        return $class::fromArray($payloadData);
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

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(array $data, string $key): string
    {
        if (!isset($data[$key])) {
            throw new InvalidArgumentException(\sprintf('envelope.%s missing', $key));
        }
        if (!\is_string($data[$key])) {
            throw new InvalidArgumentException(\sprintf('envelope.%s must be a string', $key));
        }
        if ($data[$key] === '') {
            throw new InvalidArgumentException(\sprintf('envelope.%s must be non-empty', $key));
        }
        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalString(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!\is_string($data[$key]) || $data[$key] === '') {
            throw new InvalidArgumentException(\sprintf('envelope.%s must be non-empty string', $key));
        }
        return $data[$key];
    }

    /**
     * @template T of \Arcp\Ids\Id
     *
     * @param array<string, mixed> $data
     * @param class-string<T> $idClass
     *
     * @return T|null
     */
    private function optionalId(array $data, string $key, string $idClass): ?\Arcp\Ids\Id
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!\is_string($data[$key])) {
            throw new InvalidArgumentException(\sprintf('envelope.%s must be a string', $key));
        }
        return new $idClass($data[$key]);
    }
}
