<?php

declare(strict_types=1);

namespace Arcp\Messages\Human;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §12.2 — multi-option picker request. */
final readonly class HumanChoiceRequest extends MessageType
{
    /**
     * @param list<array{id: string, label: string}> $options
     */
    public function __construct(
        public string $prompt,
        public array $options,
        public \DateTimeImmutable $expiresAt,
    ) {
        if ($prompt === '') {
            throw new InvalidArgumentException('prompt missing');
        }
        if ($options === []) {
            throw new InvalidArgumentException('options must be non-empty');
        }
        foreach ($options as $opt) {
            if (!isset($opt['id'], $opt['label']) || $opt['id'] === '' || $opt['label'] === '') {
                throw new InvalidArgumentException('option needs non-empty id and label');
            }
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'human.choice.request';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'options' => $this->options,
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $prompt = $data['prompt'] ?? throw new InvalidArgumentException('prompt missing');
        $opts = $data['options'] ?? throw new InvalidArgumentException('options missing');
        $exp = $data['expires_at'] ?? throw new InvalidArgumentException('expires_at missing');
        if (!\is_string($prompt) || !\is_array($opts) || !\is_string($exp)) {
            throw new InvalidArgumentException('field types wrong');
        }
        $coerced = [];
        foreach ($opts as $opt) {
            if (
                !\is_array($opt)
                || !isset($opt['id'], $opt['label'])
                || !\is_string($opt['id'])
                || !\is_string($opt['label'])
            ) {
                throw new InvalidArgumentException('option must be {id: string, label: string}');
            }
            $coerced[] = ['id' => $opt['id'], 'label' => $opt['label']];
        }
        return new self($prompt, $coerced, new \DateTimeImmutable($exp));
    }
}
