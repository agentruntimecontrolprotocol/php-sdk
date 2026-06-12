<?php

declare(strict_types=1);

namespace Arcp\Envelope;

use Arcp\Errors\InvalidRequestException;

/**
 * Maps wire `type` strings to {@see MessageType} subclasses.
 *
 * Phase 1 ships an empty registry; Phase 2 registers the full RFC §6.2
 * catalog. Tests and the runtime each instantiate their own registry, so
 * no global mutable state is involved.
 */
final class MessageTypeRegistry
{
    /** @var array<string, class-string<MessageType>> */
    private array $byType = [];

    /**
     * Bind a type-name to a concrete subclass. The class must declare the
     * matching {@see MessageType::typeName()}. Callers SHOULD pass
     * `class-string<MessageType>` but the runtime guard accepts any string
     * to keep the boundary defensive.
     */
    public function register(string $class): void
    {
        if (!is_subclass_of($class, MessageType::class)) {
            throw new InvalidRequestException(
                \sprintf('%s is not a MessageType subclass', $class),
                ['class' => $class],
            );
        }
        $type = $class::typeName();
        if (isset($this->byType[$type])) {
            throw new InvalidRequestException(
                \sprintf(
                    'type %s already registered (existing=%s, new=%s)',
                    $type,
                    $this->byType[$type],
                    $class,
                ),
                ['type' => $type],
            );
        }
        $this->byType[$type] = $class;
    }

    /**
     * @return class-string<MessageType>|null
     */
    public function classFor(string $type): ?string
    {
        return $this->byType[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->byType[$type]);
    }

    /** @return list<string> */
    public function listTypes(): array
    {
        return array_keys($this->byType);
    }
}
