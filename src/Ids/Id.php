<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Arcp\Errors\InvalidRequestException;

/**
 * Common shape for the typed ID newtypes that wrap envelope ids.
 *
 * Every ARCP id is a non-empty string; PHPStan/Psalm refuse to mix
 * different ID classes even though they share the same wire shape.
 *
 * RFC §6.1 — envelope identifies the principal at multiple layers
 * (`id`, `session_id`, `job_id`, `stream_id`, `subscription_id`,
 * `trace_id`, `span_id`, `idempotency_key`).
 */
abstract readonly class Id implements \Stringable, \JsonSerializable
{
    final public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new InvalidRequestException(static::class . ' cannot be empty');
        }
    }

    #[\Override]
    final public function __toString(): string
    {
        return $this->value;
    }

    #[\Override]
    final public function jsonSerialize(): string
    {
        return $this->value;
    }

    final public function equals(self $other): bool
    {
        return static::class === $other::class && $this->value === $other->value;
    }

    /**
     * Construct from a JSON-decoded value. Throws on shape mismatch so the
     * caller can convert it into a structured ARCP error.
     */
    public static function fromJson(mixed $value): static
    {
        if (!\is_string($value)) {
            throw new InvalidRequestException(static::class . ' expects a JSON string');
        }
        return new static($value);
    }
}
