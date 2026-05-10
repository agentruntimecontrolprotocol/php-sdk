<?php

declare(strict_types=1);

namespace Arcp\Messages\Human;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §12.1 — human's structured response. */
final readonly class HumanInputResponse extends MessageType
{
    public function __construct(
        public mixed $value,
        public ?string $respondedBy = null,
        public ?\DateTimeImmutable $respondedAt = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'human.input.response';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['value' => $this->value];
        if ($this->respondedBy !== null) {
            $out['responded_by'] = $this->respondedBy;
        }
        if ($this->respondedAt !== null) {
            $out['responded_at'] = $this->respondedAt->format(\DateTimeInterface::RFC3339_EXTENDED);
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        if (!\array_key_exists('value', $data)) {
            throw new InvalidArgumentException('value missing');
        }
        $by = null;
        if (isset($data['responded_by']) && \is_string($data['responded_by'])) {
            $by = $data['responded_by'];
        }
        $at = null;
        if (isset($data['responded_at']) && \is_string($data['responded_at'])) {
            $at = new \DateTimeImmutable($data['responded_at']);
        }
        return new static($data['value'], $by, $at);
    }
}
