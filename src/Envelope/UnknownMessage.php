<?php

declare(strict_types=1);

namespace Arcp\Envelope;

/**
 * Opaque marker payload for envelopes whose wire `type` has no registered
 * {@see MessageType} class. ARCP v1.1 §5 requires implementations to
 * ignore unrecognized message types for forward compatibility, so the
 * serializer decodes them into this holder instead of throwing; dispatch
 * loops log and skip envelopes carrying it.
 *
 * The original wire `type` is preserved in {@see UnknownMessage::$wireType}
 * and the raw payload object in {@see UnknownMessage::$payload}, so the
 * envelope re-encodes byte-equivalently (modulo key order).
 */
final readonly class UnknownMessage extends MessageType
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $wireType,
        public array $payload = [],
    ) {
    }

    /**
     * Placeholder discriminator; never registered in a
     * {@see MessageTypeRegistry}. {@see Envelope::type()} returns the
     * preserved {@see UnknownMessage::$wireType} instead.
     */
    #[\Override]
    public static function typeName(): string
    {
        return '_unknown';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->payload;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(self::typeName(), $data);
    }
}
