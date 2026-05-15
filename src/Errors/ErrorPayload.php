<?php

declare(strict_types=1);

namespace Arcp\Errors;

use Arcp\Ids\TraceId;

/**
 * Wire-shape for an error envelope's payload (RFC §18.1).
 *
 * Codes are wire strings — canonical RFC names, namespaced extension
 * codes (e.g. `arcpx.acme.QUOTA_EXCEEDED`), or aliases like `RATE_LIMITED`.
 * Use {@see canonical()} to map to the {@see ErrorCode} enum where one
 * exists; non-canonical codes return `null`.
 *
 * @phpstan-type ErrorDetails array<string, mixed>
 */
final readonly class ErrorPayload
{
    /**
     * @param ErrorDetails $details Optional structured context (RFC §18.1).
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
     *     retryable?: bool,
     *     details?: ErrorDetails,
     *     cause?: array<string, mixed>,
     *     trace_id?: string,
     * }
     */
    public function toArray(): array
    {
        $out = [
            'code' => $this->code,
            'message' => $this->message,
        ];
        if ($this->retryable !== null) {
            $out['retryable'] = $this->retryable;
        }
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
        $code = $data['code'] ?? throw new InvalidArgumentException('error.code missing');
        $message = $data['message'] ?? throw new InvalidArgumentException('error.message missing');
        if (!\is_string($code) || !\is_string($message)) {
            throw new InvalidArgumentException('error.code/message must be strings');
        }

        $retryable = null;
        if (\array_key_exists('retryable', $data)) {
            if (!\is_bool($data['retryable'])) {
                throw new InvalidArgumentException('error.retryable must be bool');
            }
            $retryable = $data['retryable'];
        }

        $details = [];
        if (isset($data['details'])) {
            if (!\is_array($data['details'])) {
                throw new InvalidArgumentException('error.details must be an object');
            }
            /** @var array<string, mixed> $details */
            $details = $data['details'];
        }

        $cause = null;
        if (isset($data['cause'])) {
            if (!\is_array($data['cause'])) {
                throw new InvalidArgumentException('error.cause must be an object');
            }
            /** @var array<string, mixed> $causeData */
            $causeData = $data['cause'];
            $cause = self::fromArray($causeData);
        }

        $traceId = null;
        if (isset($data['trace_id'])) {
            $traceId = TraceId::fromJson($data['trace_id']);
        }

        return new self($code, $message, $retryable, $details, $cause, $traceId);
    }
}
