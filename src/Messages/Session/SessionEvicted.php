<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §8.5 — runtime evicted the session (idle, revoked credentials, quota, …). */
final readonly class SessionEvicted extends MessageType
{
    public function __construct(
        public string $reason,
        public ?string $code = null,
    ) {
        if ($reason === '') {
            throw new InvalidArgumentException('reason must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.evicted';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['reason' => $this->reason];
        if ($this->code !== null) {
            $out['code'] = $this->code;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $reason = $data['reason'] ?? throw new InvalidArgumentException('reason missing');
        if (!\is_string($reason)) {
            throw new InvalidArgumentException('reason must be string');
        }
        $code = null;
        if (isset($data['code'])) {
            if (!\is_string($data['code'])) {
                throw new InvalidArgumentException('code must be string');
            }
            $code = $data['code'];
        }
        return new static($reason, $code);
    }
}
