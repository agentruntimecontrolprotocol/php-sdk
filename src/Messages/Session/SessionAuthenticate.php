<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §8.1 — challenge response. */
final readonly class SessionAuthenticate extends MessageType
{
    public function __construct(public Auth $auth)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.authenticate';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['auth' => $this->auth->toArray()];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $auth = $data['auth'] ?? throw new InvalidArgumentException('auth missing');
        if (!\is_array($auth)) {
            throw new InvalidArgumentException('auth must be object');
        }
        /** @var array<string, mixed> $auth */
        return new self(Auth::fromArray($auth));
    }
}
