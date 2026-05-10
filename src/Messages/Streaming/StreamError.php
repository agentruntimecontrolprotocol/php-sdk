<?php

declare(strict_types=1);

namespace Arcp\Messages\Streaming;

use Arcp\Envelope\MessageType;
use Arcp\Errors\ErrorPayload;

/** RFC §11 — terminal failure. RFC §10.4: `code: CANCELLED` ends a cancelled stream. */
final readonly class StreamError extends MessageType
{
    public function __construct(public ErrorPayload $error)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'stream.error';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->error->toArray();
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new static(ErrorPayload::fromArray($data));
    }
}
