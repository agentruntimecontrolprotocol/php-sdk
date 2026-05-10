<?php

declare(strict_types=1);

namespace Arcp\Errors;

/**
 * RFC §18.2 — unknown error fallback. The serializer maps any inbound
 * code that is neither a recognized canonical code nor a properly
 * namespaced extension code into this exception.
 */
final class UnknownException extends ARCPException
{
    public function __construct(
        public readonly string $rawCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : \sprintf('unknown error code: %s', $rawCode),
            ['raw_code' => $rawCode],
            null,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::Unknown;
    }
}
