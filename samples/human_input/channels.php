<?php

declare(strict_types=1);

namespace Arcp\Samples\HumanInput;

/**
 * Per-destination channel adapters. Real versions wrap ntfy.sh, SES,
 * and the Slack web API. Each returns a value matching the request's
 * `response_schema`.
 */
final class ChannelRegistry
{
    /** @var array<string, callable(string, array<string, mixed>): mixed> */
    private array $handlers;

    /** @param array<string, callable(string, array<string, mixed>): mixed> $handlers */
    public function __construct(array $handlers)
    {
        $this->handlers = $handlers;
    }

    public static function default(): self
    {
        return new self([
            'ntfy:phone' => static fn (string $p, array $s) => throw new \RuntimeException('not implemented'),
            'email:oncall' => static fn (string $p, array $s) => throw new \RuntimeException('not implemented'),
            'slack:ops' => static fn (string $p, array $s) => throw new \RuntimeException('not implemented'),
        ]);
    }

    /** @param array<string, mixed> $schema */
    public function ask(string $dest, string $prompt, array $schema): mixed
    {
        if (!isset($this->handlers[$dest])) {
            throw new \RuntimeException("unknown channel: {$dest}");
        }
        return ($this->handlers[$dest])($prompt, $schema);
    }
}
