<?php

declare(strict_types=1);

namespace Arcp\Errors;

/** ARCP v1.1 §12 — cost budget counter reached zero or below. */
final class BudgetExhaustedException extends ARCPException
{
    public function __construct(
        string $currency,
        int|float|string $remaining,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : \sprintf('cost budget exhausted for %s', $currency),
            ['currency' => $currency, 'remaining' => $remaining],
            false,
            $previous,
        );
    }

    #[\Override]
    public function code(): ErrorCode
    {
        return ErrorCode::BudgetExhausted;
    }
}
