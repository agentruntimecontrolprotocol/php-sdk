<?php

declare(strict_types=1);

namespace Arcp\Envelope;

/**
 * Sealed-style base for every wire-level message payload (RFC §6.2).
 *
 * The PHP type system can't enforce sealed hierarchies — we approximate
 * with `abstract class` + every subclass `final readonly class`. Dispatch
 * uses `match (true) { $msg instanceof FooMessage => ..., }`. Exhaustive
 * matching is a static-analysis property (PHPStan max + strict-rules),
 * not a compiler property; uncovered arms surface as analysis errors.
 *
 * Each subclass:
 *  - exposes the wire `type` via {@see MessageType::typeName()}
 *  - rebuilds itself from JSON via {@see MessageType::fromArray()}
 *  - serializes to wire shape via {@see MessageType::toArray()}
 */
abstract readonly class MessageType
{
    /** RFC §6.2 type-name (e.g. `tool.invoke`). */
    abstract public static function typeName(): string;

    /**
     * Reconstruct from a JSON-decoded `payload` object.
     *
     * @param array<string, mixed> $data
     */
    abstract public static function fromArray(array $data): static;

    /**
     * @return array<string, mixed> Wire payload shape; the {@see Envelope}
     *                              owns the surrounding fields.
     */
    abstract public function toArray(): array;
}
