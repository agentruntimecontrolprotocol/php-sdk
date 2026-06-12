<?php

declare(strict_types=1);

namespace Arcp\Errors;

use Arcp\Ids\TraceId;

/**
 * Wire-shape for an error envelope's payload (RFC §18.1).
 *
 * Codes are wire strings — canonical RFC names, namespaced extension
 * codes (e.g. `arcpx.acme.QUOTA_EXCEEDED`), or aliases like `RATE_LIMITED`.
 * {@see canonical()} maps aliases to their canonical code
 * (e.g. `RATE_LIMITED` → `RESOURCE_EXHAUSTED`); namespaced/extension codes
 * with no enum equivalent return `null`.
 *
 * @phpstan-type ErrorDetails array<string, mixed>
 */
final readonly class ErrorPayload
{
    /**
     * @param ErrorDetails $details Optional structured context (RFC §18.1).
     *
     * @size-check-suppress Wire-shape DTO mapping to RFC §18.1.
     */
    public function __construct(
        public string $code,
        public string $message,
        public ?bool $retryable = null,
        public array $details = [],
        public ?ErrorPayload $cause = null,
        public ?TraceId $traceId = null,
    ) {
        if ($code === '') {
            throw new InvalidArgumentException('error code must be non-empty');
        }
        if ($message === '') {
            throw new InvalidArgumentException('error message must be non-empty');
        }
    }

    public static function fromException(ARCPException $e): self
    {
        return new self(
            code: $e->code()->value,
            message: $e->getMessage(),
            retryable: $e->isRetryable(),
            details: $e->details,
        );
    }

    /**
     * The effective retryability emitted on the wire: an explicit flag wins,
     * otherwise the canonical code's default (§12), defaulting to false for
     * namespaced/extension codes with no enum mapping.
     */
    public function effectiveRetryable(): bool
    {
        return $this->retryable ?? ($this->canonical()?->defaultRetryable() ?? false);
    }

    /** Map the wire code to a canonical {@see ErrorCode}, when possible. */
    public function canonical(): ?ErrorCode
    {
        // RATE_LIMITED is an alias for RESOURCE_EXHAUSTED per RFC §18.2.
        if ($this->code === 'RATE_LIMITED') {
            return ErrorCode::ResourceExhausted;
        }
        return ErrorCode::tryFrom($this->code);
    }

    /** True if the code is namespaced under `arcpx.<vendor>.<NAME>`. */
    public function isNamespaced(): bool
    {
        return preg_match('/^[a-z][a-z0-9-]*\.[A-Za-z0-9_.-]+$/', $this->code) === 1
            && !$this->canonical() instanceof ErrorCode;
    }

    /**
     * @return array{
     *     code: string,
     *     message: string,
     *     retryable: bool,
     *     details?: ErrorDetails,
     *     cause?: array<string, mixed>,
     *     trace_id?: string,
     * }
     */
    public function toArray(): array
    {
        // §12: error payloads MUST carry a retryable boolean. Emit the
        // effective value even when not set explicitly so LEASE_EXPIRED /
        // BUDGET_EXHAUSTED always travel as retryable:false and
        // INTERNAL_ERROR as retryable:true.
        $out = [
            'code' => $this->code,
            'message' => $this->message,
            'retryable' => $this->effectiveRetryable(),
        ];
        if ($this->details !== []) {
            $out['details'] = $this->details;
        }
        if ($this->cause instanceof \Arcp\Errors\ErrorPayload) {
            $out['cause'] = $this->cause->toArray();
        }
        if ($this->traceId instanceof TraceId) {
            $out['trace_id'] = (string) $this->traceId;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        [$code, $message] = self::requiredStrings($data);
        return new self(
            $code,
            $message,
            self::retryableFromArray($data),
            self::detailsFromArray($data),
            self::causeFromArray($data),
            isset($data['trace_id']) ? TraceId::fromJson($data['trace_id']) : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: string, 1: string}
     */
    private static function requiredStrings(array $data): array
    {
        $code = $data['code'] ?? throw new InvalidArgumentException('error.code missing');
        $message = $data['message']
            ?? throw new InvalidArgumentException('error.message missing');
        if (!\is_string($code) || !\is_string($message)) {
            throw new InvalidArgumentException('error.code/message must be strings');
        }
        return [$code, $message];
    }

    /** @param array<string, mixed> $data */
    private static function retryableFromArray(array $data): ?bool
    {
        if (!\array_key_exists('retryable', $data)) {
            return null;
        }
        if (!\is_bool($data['retryable'])) {
            throw new InvalidArgumentException('error.retryable must be bool');
        }
        return $data['retryable'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function detailsFromArray(array $data): array
    {
        if (!isset($data['details'])) {
            return [];
        }
        if (!\is_array($data['details'])) {
            throw new InvalidArgumentException('error.details must be an object');
        }
        /** @var array<string, mixed> $details */
        $details = $data['details'];
        return $details;
    }

    /** @param array<string, mixed> $data */
    private static function causeFromArray(array $data): ?self
    {
        if (!isset($data['cause'])) {
            return null;
        }
        if (!\is_array($data['cause'])) {
            throw new InvalidArgumentException('error.cause must be an object');
        }
        /** @var array<string, mixed> $causeData */
        $causeData = $data['cause'];
        return self::fromArray($causeData);
    }
}
