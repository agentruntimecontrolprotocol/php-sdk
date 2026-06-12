<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/**
 * ARCP v1.1 §8.1 — job event: `{kind, ts, body}`. The `kind`
 * discriminator selects the §8.2 body shape; `body` is held generically
 * so unknown kinds pass through untouched.
 */
final readonly class JobEvent extends MessageType
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public string $eventKind,
        public \DateTimeImmutable $ts,
        public array $body = [],
    ) {
        if ($eventKind === '') {
            throw new InvalidRequestException('job.event kind missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.event';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'kind' => $this->eventKind,
            'ts' => $this->ts->format(\DateTimeInterface::RFC3339_EXTENDED),
            'body' => $this->body,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $kind = $data['kind'] ?? throw new InvalidRequestException('job.event kind missing');
        if (!\is_string($kind)) {
            throw new InvalidRequestException('job.event kind must be string');
        }
        $ts = $data['ts'] ?? throw new InvalidRequestException('job.event ts missing');
        if (!\is_string($ts)) {
            throw new InvalidRequestException('job.event ts must be string');
        }
        $body = [];
        if (isset($data['body'])) {
            if (!\is_array($data['body'])) {
                throw new InvalidRequestException('job.event body must be object');
            }
            /** @var array<string, mixed> $body */
            $body = $data['body'];
        }
        try {
            return new self($kind, new \DateTimeImmutable($ts), $body);
        } catch (\DateMalformedStringException $e) {
            throw new InvalidRequestException('job.event ts must be RFC 3339', ['ts' => $ts], null, $e);
        }
    }
}
