<?php

declare(strict_types=1);

namespace Arcp\Messages\Human;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** RFC §12.4 — request cancelled / expired / superseded. */
final readonly class HumanInputCancelled extends MessageType
{
    public function __construct(
        public string $code,
        public string $reason = '',
    ) {
        if ($code === '') {
            throw new InvalidRequestException('code missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'human.input.cancelled';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['code' => $this->code];
        if ($this->reason !== '') {
            $out['reason'] = $this->reason;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $code = $data['code'] ?? throw new InvalidRequestException('code missing');
        if (!\is_string($code)) {
            throw new InvalidRequestException('code must be string');
        }
        $reason = '';
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        return new self($code, $reason);
    }
}
