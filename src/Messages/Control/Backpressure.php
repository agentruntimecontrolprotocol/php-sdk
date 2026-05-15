<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;

/** RFC §11.2 — slow-down signal to a sender. */
final readonly class Backpressure extends MessageType
{
    public function __construct(
        public ?int $desiredRatePerSecond = null,
        public ?int $bufferRemainingBytes = null,
        public ?string $reason = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'backpressure';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [];
        if ($this->desiredRatePerSecond !== null) {
            $out['desired_rate_per_second'] = $this->desiredRatePerSecond;
        }
        if ($this->bufferRemainingBytes !== null) {
            $out['buffer_remaining_bytes'] = $this->bufferRemainingBytes;
        }
        if ($this->reason !== null) {
            $out['reason'] = $this->reason;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $rate = null;
        if (isset($data['desired_rate_per_second']) && \is_int($data['desired_rate_per_second'])) {
            $rate = $data['desired_rate_per_second'];
        }
        $buf = null;
        if (isset($data['buffer_remaining_bytes']) && \is_int($data['buffer_remaining_bytes'])) {
            $buf = $data['buffer_remaining_bytes'];
        }
        $reason = null;
        if (isset($data['reason']) && \is_string($data['reason'])) {
            $reason = $data['reason'];
        }
        return new self($rate, $buf, $reason);
    }
}
