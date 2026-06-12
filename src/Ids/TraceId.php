<?php

declare(strict_types=1);

namespace Arcp\Ids;

use Arcp\Errors\InvalidArgumentException;

/**
 * Distributed tracing root id (RFC §11). ARCP propagates W3C Trace
 * Context: the `trace_id` envelope field carries the `traceparent` value
 * (`00-<32 hex trace-id>-<16 hex parent-id>-<2 hex flags>`), preserved
 * across delegation so multi-runtime traces remain coherent.
 */
final readonly class TraceId extends Id
{
    private const string TRACEPARENT_PATTERN =
        '/^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/';

    /** Generate a fresh, valid W3C `traceparent` value. */
    public static function random(): self
    {
        return new self(\sprintf(
            '00-%s-%s-01',
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(8)),
        ));
    }

    /**
     * Validate and wrap a W3C `traceparent` string. Rejects malformed
     * values and the all-zero trace-id / parent-id reserved by the spec.
     */
    public static function fromTraceparent(string $traceparent): self
    {
        if (preg_match(self::TRACEPARENT_PATTERN, $traceparent) !== 1) {
            throw new InvalidArgumentException('invalid W3C traceparent: ' . $traceparent);
        }
        $traceComponent = substr($traceparent, 3, 32);
        $parentComponent = substr($traceparent, 36, 16);
        if (str_repeat('0', 32) === $traceComponent || str_repeat('0', 16) === $parentComponent) {
            throw new InvalidArgumentException('traceparent has an all-zero id: ' . $traceparent);
        }
        return new self($traceparent);
    }

    /**
     * The 32-hex `trace-id` component, for composing child span contexts.
     * Returns null when the id is a legacy/opaque value rather than a
     * W3C traceparent (accepted on inbound decode for interop).
     */
    public function traceComponent(): ?string
    {
        if (preg_match(self::TRACEPARENT_PATTERN, $this->value) !== 1) {
            return null;
        }
        return substr($this->value, 3, 32);
    }
}
