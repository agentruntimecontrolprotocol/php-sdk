<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\ErrorPayload;

/** RFC §18.1 — terminal failure of a direct tool invocation. */
final readonly class ToolError extends MessageType
{
    public function __construct(public ErrorPayload $error)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'tool.error';
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->error->toArray();
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(ErrorPayload::fromArray($data));
    }
}
