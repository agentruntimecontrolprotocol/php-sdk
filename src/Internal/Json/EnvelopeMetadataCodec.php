<?php

declare(strict_types=1);

namespace Arcp\Internal\Json;

use Arcp\Envelope\Envelope;
use Arcp\Envelope\Priority;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Ids\Id;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SpanId;
use Arcp\Ids\StreamId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;

/**
 * Pure functions for shuffling envelope metadata between {@see Envelope}
 * instances and their RFC §6.1 array shape. Extracted from
 * {@see \Arcp\Json\EnvelopeSerializer} to keep that class under the
 * 300-line hard limit; carries no state of its own.
 *
 * @internal
 */
final readonly class EnvelopeMetadataCodec
{
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
        ];
        if ($env->priority !== Priority::Normal) {
            $out['priority'] = $env->priority->value;
        }
        return [
            ...$out,
            ...$this->scopeIdsToArray($env),
            ...$this->traceIdsToArray($env),
            ...$this->correlationIdsToArray($env),
            ...$this->endpointsToArray($env),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function scopeIdsToArray(Envelope $env): array
    {
        $out = [];
        if ($env->sessionId instanceof SessionId) {
            $out['session_id'] = (string) $env->sessionId;
        }
        if ($env->jobId instanceof JobId) {
            $out['job_id'] = (string) $env->jobId;
        }
        if ($env->streamId instanceof StreamId) {
            $out['stream_id'] = (string) $env->streamId;
        }
        if ($env->subscriptionId instanceof SubscriptionId) {
            $out['subscription_id'] = (string) $env->subscriptionId;
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function traceIdsToArray(Envelope $env): array
    {
        $out = [];
        if ($env->traceId instanceof TraceId) {
            $out['trace_id'] = (string) $env->traceId;
        }
        if ($env->spanId instanceof SpanId) {
            $out['span_id'] = (string) $env->spanId;
        }
        if ($env->parentSpanId instanceof SpanId) {
            $out['parent_span_id'] = (string) $env->parentSpanId;
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function correlationIdsToArray(Envelope $env): array
    {
        $out = [];
        if ($env->correlationId instanceof MessageId) {
            $out['correlation_id'] = (string) $env->correlationId;
        }
        if ($env->causationId instanceof MessageId) {
            $out['causation_id'] = (string) $env->causationId;
        }
        if ($env->idempotencyKey instanceof IdempotencyKey) {
            $out['idempotency_key'] = (string) $env->idempotencyKey;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function endpointsToArray(Envelope $env): array
    {
        $out = [];
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

    public function parseTimestamp(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                'envelope.timestamp must be RFC 3339',
                ['timestamp' => $value],
                null,
                $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function parsePriority(array $data): Priority
    {
        if (!isset($data['priority'])) {
            return Priority::Normal;
        }
        if (!\is_string($data['priority']) || Priority::tryFrom($data['priority']) === null) {
            throw new InvalidArgumentException(
                'envelope.priority not recognized',
                ['priority' => $data['priority']],
            );
        }
        return Priority::from($data['priority']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function parseExtensions(array $data): array
    {
        if (!isset($data['extensions'])) {
            return [];
        }
        if (!\is_array($data['extensions'])) {
            throw new InvalidArgumentException('envelope.extensions must be object');
        }
        /** @var array<string, mixed> $extensions */
        $extensions = $data['extensions'];
        return $extensions;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function requireString(array $data, string $key): string
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
    public function optionalString(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!\is_string($data[$key]) || $data[$key] === '') {
            throw new InvalidArgumentException(
                \sprintf('envelope.%s must be non-empty string', $key),
            );
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
    public function optionalId(array $data, string $key, string $idClass): ?Id
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
