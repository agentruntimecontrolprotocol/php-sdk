<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Errors\InvalidArgumentException;
use Arcp\Envelope\MessageType;
use Arcp\Errors\ErrorPayload;

/** RFC §8.1 — server refused the session for non-auth reasons (e.g. UNIMPLEMENTED). */
final readonly class SessionRejected extends MessageType
{
    public function __construct(public ErrorPayload $error)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.rejected';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['error' => $this->error->toArray()];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $err = $data['error'] ?? [];
        if (!\is_array($err)) {
            throw new InvalidArgumentException('error must be object');
        }
        /** @var array<string, mixed> $err */
        return new static(ErrorPayload::fromArray($err));
    }
}
