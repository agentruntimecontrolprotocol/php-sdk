<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** ARCP v1.1 §6.6 — paginated job inventory response. */
final readonly class Jobs extends MessageType
{
    /**
     * @param list<array<string, mixed>> $jobs
     */
    public function __construct(
        public string $requestId,
        public array $jobs,
        public ?string $nextCursor = null,
    ) {
        if ($requestId === '') {
            throw new InvalidArgumentException('request_id missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.jobs';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'jobs' => $this->jobs,
            'next_cursor' => $this->nextCursor,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $requestId = $data['request_id'] ?? throw new InvalidArgumentException('request_id missing');
        if (!\is_string($requestId)) {
            throw new InvalidArgumentException('request_id must be string');
        }
        $jobs = $data['jobs'] ?? throw new InvalidArgumentException('jobs missing');
        if (!\is_array($jobs)) {
            throw new InvalidArgumentException('jobs must be list');
        }
        $out = [];
        foreach ($jobs as $job) {
            if (!\is_array($job)) {
                throw new InvalidArgumentException('jobs entries must be objects');
            }
            /** @var array<string, mixed> $job */
            $out[] = $job;
        }
        $nextCursor = $data['next_cursor'] ?? null;
        if ($nextCursor !== null && !\is_string($nextCursor)) {
            throw new InvalidArgumentException('next_cursor must be string or null');
        }
        return new self($requestId, $out, $nextCursor);
    }
}
