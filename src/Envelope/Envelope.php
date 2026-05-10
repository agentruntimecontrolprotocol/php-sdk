<?php

declare(strict_types=1);

namespace Arcp\Envelope;

use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SpanId;
use Arcp\Ids\StreamId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;
use Arcp\Version;

/**
 * Canonical ARCP message container (RFC §6.1).
 *
 * Required fields: `arcp`, `id`, `type`, `timestamp`, `payload`.
 * Conditional fields are populated based on context (sessions, jobs,
 * streams, subscriptions). `correlation_id` and `causation_id` are
 * **distinct**: `correlation_id` answers "which command does this
 * respond to?", `causation_id` answers "which immediately upstream
 * message produced this?".
 *
 * @phpstan-type ExtensionMap array<string, mixed>
 */
final readonly class Envelope
{
    /**
     * @param ExtensionMap $extensions
     */
    public function __construct(
        public MessageId $id,
        public MessageType $payload,
        public \DateTimeImmutable $timestamp,
        public Priority $priority = Priority::Normal,
        public ?SessionId $sessionId = null,
        public ?JobId $jobId = null,
        public ?StreamId $streamId = null,
        public ?SubscriptionId $subscriptionId = null,
        public ?TraceId $traceId = null,
        public ?SpanId $spanId = null,
        public ?SpanId $parentSpanId = null,
        public ?MessageId $correlationId = null,
        public ?MessageId $causationId = null,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?string $source = null,
        public ?string $target = null,
        public string $arcp = Version::PROTOCOL_VERSION,
        public array $extensions = [],
    ) {
        if (trim($this->arcp) === '') {
            throw new \InvalidArgumentException('envelope.arcp must be non-empty');
        }
        if ($this->source !== null && trim($this->source) === '') {
            throw new \InvalidArgumentException('envelope.source must be non-empty when present');
        }
        if ($this->target !== null && trim($this->target) === '') {
            throw new \InvalidArgumentException('envelope.target must be non-empty when present');
        }
    }

    public function type(): string
    {
        return $this->payload::typeName();
    }

    /**
     * Return a copy with a different `correlationId`. Used when the
     * runtime answers a command and needs to point at the incoming
     * message id.
     */
    public function withCorrelationId(?MessageId $correlationId): self
    {
        return new self(
            id: $this->id,
            payload: $this->payload,
            timestamp: $this->timestamp,
            priority: $this->priority,
            sessionId: $this->sessionId,
            jobId: $this->jobId,
            streamId: $this->streamId,
            subscriptionId: $this->subscriptionId,
            traceId: $this->traceId,
            spanId: $this->spanId,
            parentSpanId: $this->parentSpanId,
            correlationId: $correlationId,
            causationId: $this->causationId,
            idempotencyKey: $this->idempotencyKey,
            source: $this->source,
            target: $this->target,
            arcp: $this->arcp,
            extensions: $this->extensions,
        );
    }
}
