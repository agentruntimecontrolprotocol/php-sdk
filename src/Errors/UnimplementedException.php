<?php

declare(strict_types=1);

namespace Arcp\Errors;

/**
 * RFC §18.2 — feature not supported by this runtime. The build prompt's
 * "Out of scope for v0.1" surfaces all throw this; the `section` field
 * pins the RFC reference so callers can route to the right v0.2 issue.
 */
final class UnimplementedException extends ARCPException
{
    public function __construct(
        public readonly string $section,
        string $detail = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $detail !== '' ? \sprintf('%s: %s', $section, $detail) : $section,
            ['section' => $section, 'detail' => $detail],
            false,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::Unimplemented;
    }
}
