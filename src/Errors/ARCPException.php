<?php

declare(strict_types=1);

namespace Arcp\Errors;

/**
 * Abstract base for every ARCP-protocol exception. Each concrete subclass
 * binds to one {@see ErrorCode} and carries typed context (lease ids,
 * deadlines, etc.) so callers don't have to fish through unstructured
 * `details` arrays.
 *
 * RFC §18.1 defines the wire shape; this class is the in-process
 * representation that round-trips through {@see Arcp\Json\EnvelopeSerializer}.
 */
abstract class ARCPException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details Optional structured context attached
     *                                      to the wire envelope's `details` field.
     */
    public function __construct(
        string $message,
        public readonly array $details = [],
        public readonly ?bool $retryable = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    abstract public function code(): ErrorCode;

    /**
     * Effective retryability — explicit override wins, otherwise the
     * RFC §18.3 default for this code.
     */
    public function isRetryable(): bool
    {
        return $this->retryable ?? $this->code()->defaultRetryable();
    }
}
