<?php

declare(strict_types=1);

namespace Arcp\Messages\Telemetry;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §17.2 — structured log line. */
final readonly class LogEvent extends MessageType
{
    public const array LEVELS = ['trace', 'debug', 'info', 'warn', 'error', 'critical'];

    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $level,
        public string $message,
        public array $attributes = [],
    ) {
        if (!\in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('log.level must be one of ' . implode(',', self::LEVELS));
        }
        if ($message === '') {
            throw new InvalidArgumentException('log.message must be non-empty');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'log';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['level' => $this->level, 'message' => $this->message];
        if ($this->attributes !== []) {
            $out['attributes'] = $this->attributes;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $level = $data['level'] ?? throw new InvalidArgumentException('level missing');
        $msg = $data['message'] ?? throw new InvalidArgumentException('message missing');
        if (!\is_string($level) || !\is_string($msg)) {
            throw new InvalidArgumentException('level/message must be strings');
        }
        $attrs = [];
        if (isset($data['attributes'])) {
            if (!\is_array($data['attributes'])) {
                throw new InvalidArgumentException('attributes must be object');
            }
            /** @var array<string, mixed> $attrs */
            $attrs = $data['attributes'];
        }
        return new self($level, $msg, $attrs);
    }
}
