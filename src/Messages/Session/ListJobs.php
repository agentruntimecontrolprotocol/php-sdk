<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** ARCP v1.1 §6.6 — request an inventory page of visible jobs. */
final readonly class ListJobs extends MessageType
{
    /**
     * @param array<string, mixed> $filter
     */
    public function __construct(
        public array $filter = [],
        public int $limit = 50,
        public ?string $cursor = null,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('limit must be positive');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.list_jobs';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['limit' => $this->limit];
        if ($this->filter !== []) {
            $out['filter'] = $this->filter;
        }
        if ($this->cursor !== null) {
            $out['cursor'] = $this->cursor;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $filter = [];
        if (isset($data['filter'])) {
            if (!\is_array($data['filter'])) {
                throw new InvalidArgumentException('filter must be object');
            }
            /** @var array<string, mixed> $filter */
            $filter = $data['filter'];
        }
        $limit = isset($data['limit']) && \is_int($data['limit']) ? $data['limit'] : 50;
        $cursor = null;
        if (isset($data['cursor'])) {
            if (!\is_string($data['cursor'])) {
                throw new InvalidArgumentException('cursor must be string');
            }
            $cursor = $data['cursor'];
        }
        return new self($filter, $limit, $cursor);
    }
}
