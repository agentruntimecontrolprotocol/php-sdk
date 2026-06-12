<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** ARCP v1.1 §12 — requested `agent` is not registered. */
final class AgentNotAvailableException extends ARCPException
{
    public function __construct(
        string $agent,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : \sprintf('agent not available: %s', $agent),
            ['agent' => $agent],
            false,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::AgentNotAvailable;
    }
}
