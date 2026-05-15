<?php

declare(strict_types=1);

namespace Arcp\Errors;

/**
 * Root marker for every exception thrown by the ARCP SDK.
 *
 * Consumers catch this single interface to handle anything the library
 * can throw. PHP_SDK_GUIDE §5: "Consumers can catch one type to catch
 * everything from you."
 *
 * The abstract {@see ARCPException} is the canonical implementation and
 * remains the base class for every concrete protocol error. New
 * exception types added in the future MUST implement this interface,
 * but they MAY extend a different SPL parent (e.g. an authentication
 * error wrapping a `\RuntimeException` from a JWT library) so long as
 * they also implement this marker.
 *
 * RFC §18.1 defines the wire shape; this interface is the in-process
 * surface that callers depend on.
 */
interface ARCPExceptionInterface extends \Throwable
{
    /**
     * Stable ARCP error code (RFC §18.2). Concrete subclasses bind to
     * exactly one value.
     */
    public function code(): ErrorCode;

    /**
     * Effective retryability — explicit override on the exception
     * instance wins, otherwise the RFC §18.3 default for this code.
     */
    public function isRetryable(): bool;
}
