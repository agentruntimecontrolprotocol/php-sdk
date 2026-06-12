<?php

declare(strict_types=1);

namespace Arcp\Messages\Telemetry;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** RFC §17.1 — typed span event. The envelope already carries trace_id/span_id. */
final readonly class TraceSpan extends MessageType
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $name,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $endedAt,
        public array $attributes = [],
        public string $status = 'ok',
    ) {
        if ($name === '') {
            throw new InvalidRequestException('span name missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'trace.span';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'started_at' => $this->startedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'ended_at' => $this->endedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'status' => $this->status,
        ];
        if ($this->attributes !== []) {
            $out['attributes'] = $this->attributes;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? throw new InvalidRequestException('name missing');
        $start = $data['started_at'] ?? throw new InvalidRequestException('started_at missing');
        $end = $data['ended_at'] ?? throw new InvalidRequestException('ended_at missing');
        if (!\is_string($name) || !\is_string($start) || !\is_string($end)) {
            throw new InvalidRequestException('name/started_at/ended_at must be strings');
        }
        $attrs = [];
        if (isset($data['attributes'])) {
            if (!\is_array($data['attributes'])) {
                throw new InvalidRequestException('attributes must be object');
            }
            /** @var array<string, mixed> $attrs */
            $attrs = $data['attributes'];
        }
        $status = 'ok';
        if (isset($data['status']) && \is_string($data['status'])) {
            $status = $data['status'];
        }
        return new self(
            $name,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            $attrs,
            $status,
        );
    }
}
