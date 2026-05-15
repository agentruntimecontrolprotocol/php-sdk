<?php

declare(strict_types=1);

namespace Arcp\Messages\Human;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §12.1 — request structured human input. */
final readonly class HumanInputRequest extends MessageType
{
    /**
     * @param array<string, mixed> $responseSchema JSON Schema; runtime validates `value`.
     * @param array<string, mixed>|null $default Optional fallback used on expiry.
     */
    public function __construct(
        public string $prompt,
        public array $responseSchema,
        public \DateTimeImmutable $expiresAt,
        public ?array $default = null,
    ) {
        if ($prompt === '') {
            throw new InvalidArgumentException('prompt missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'human.input.request';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [
            'prompt' => $this->prompt,
            'response_schema' => $this->responseSchema,
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
        if ($this->default !== null) {
            $out['default'] = $this->default;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $prompt = $data['prompt'] ?? throw new InvalidArgumentException('prompt missing');
        $schema = $data['response_schema']
            ?? throw new InvalidArgumentException('response_schema missing');
        $exp = $data['expires_at'] ?? throw new InvalidArgumentException('expires_at missing');
        if (!\is_string($prompt) || !\is_array($schema) || !\is_string($exp)) {
            throw new InvalidArgumentException(
                'prompt/response_schema/expires_at have wrong types',
            );
        }
        $default = null;
        if (isset($data['default'])) {
            if (!\is_array($data['default'])) {
                throw new InvalidArgumentException('default must be object');
            }
            /** @var array<string, mixed> $default */
            $default = $data['default'];
        }
        /** @var array<string, mixed> $schema */
        return new self($prompt, $schema, new \DateTimeImmutable($exp), $default);
    }
}
