<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\ErrorPayload;

/** RFC §10.2 — terminal: job failed. */
final readonly class JobFailed extends MessageType
{
    public function __construct(public ErrorPayload $error)
    {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.failed';
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
