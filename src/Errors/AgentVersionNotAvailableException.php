<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** ARCP v1.1 §12 — requested agent exists, but the pinned version does not. */
final class AgentVersionNotAvailableException extends ARCPException
{
    public function __construct(
        string $agent,
        string $version,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : \sprintf('agent version not available: %s@%s', $agent, $version),
            ['agent' => $agent, 'version' => $version],
            false,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::AgentVersionNotAvailable;
    }
}
