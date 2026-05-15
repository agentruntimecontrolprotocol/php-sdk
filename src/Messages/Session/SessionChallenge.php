<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §8.1 — challenge issued before acceptance. */
final readonly class SessionChallenge extends MessageType
{
    public function __construct(
        public string $challenge,
        public string $scheme = 'bearer',
    ) {
        if ($challenge === '') {
            throw new InvalidArgumentException('challenge must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.challenge';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['challenge' => $this->challenge, 'scheme' => $this->scheme];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $c = $data['challenge'] ?? throw new InvalidArgumentException('challenge missing');
        if (!\is_string($c)) {
            throw new InvalidArgumentException('challenge must be string');
        }
        $scheme = 'bearer';
        if (isset($data['scheme'])) {
            if (!\is_string($data['scheme'])) {
                throw new InvalidArgumentException('scheme must be string');
            }
            $scheme = $data['scheme'];
        }
        return new self($c, $scheme);
    }
}
