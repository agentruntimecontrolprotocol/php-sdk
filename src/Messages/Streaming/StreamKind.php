<?php

declare(strict_types=1);

namespace Arcp\Messages\Streaming;

/** RFC §11.1 — declared stream kinds. Unknown values fold to {@see StreamKind::Event}. */
enum StreamKind: string
{
    case Text = 'text';
    case Binary = 'binary';
    case Event = 'event';
    case Log = 'log';
    case Metric = 'metric';
    case Thought = 'thought';

    public static function parse(string $raw): self
    {
        return self::tryFrom($raw) ?? self::Event;
    }
}
