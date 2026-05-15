<?php

declare(strict_types=1);

namespace Arcp\Messages\Human;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §12.2 — chosen option id. */
final readonly class HumanChoiceResponse extends MessageType
{
    public function __construct(
        public string $choiceId,
        public ?string $respondedBy = null,
        public ?\DateTimeImmutable $respondedAt = null,
    ) {
        if ($choiceId === '') {
            throw new InvalidArgumentException('choice_id missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'human.choice.response';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['choice_id' => $this->choiceId];
        if ($this->respondedBy !== null) {
            $out['responded_by'] = $this->respondedBy;
        }
        if ($this->respondedAt instanceof \DateTimeImmutable) {
            $out['responded_at'] = $this->respondedAt->format(\DateTimeInterface::RFC3339_EXTENDED);
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['choice_id'] ?? throw new InvalidArgumentException('choice_id missing');
        if (!\is_string($id)) {
            throw new InvalidArgumentException('choice_id must be string');
        }
        $by = null;
        if (isset($data['responded_by']) && \is_string($data['responded_by'])) {
            $by = $data['responded_by'];
        }
        $at = null;
        if (isset($data['responded_at']) && \is_string($data['responded_at'])) {
            $at = new \DateTimeImmutable($data['responded_at']);
        }
        return new self($id, $by, $at);
    }
}
