<?php

declare(strict_types=1);

namespace Arcp\Messages\Execution;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §10.2 — runtime accepted the job; envelope `job_id` carries the new id. */
final readonly class JobAccepted extends MessageType
{
    /**
     * @param list<array<string, mixed>>|null $credentials
     */
    public function __construct(
        public ?string $note = null,
        public ?array $credentials = null,
    ) {
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'job.accepted';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [];
        if ($this->note !== null) {
            $out['note'] = $this->note;
        }
        if ($this->credentials !== null) {
            $out['credentials'] = $this->credentials;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $note = null;
        if (isset($data['note']) && \is_string($data['note'])) {
            $note = $data['note'];
        }
        return new self($note, self::credentialsFromArray($data));
    }

    public function redacted(): self
    {
        if ($this->credentials === null) {
            return $this;
        }
        $credentials = [];
        foreach ($this->credentials as $credential) {
            $credentials[] = [...$credential, 'value' => '***'];
        }
        return new self($this->note, $credentials);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>|null
     */
    private static function credentialsFromArray(array $data): ?array
    {
        if (!isset($data['credentials'])) {
            return null;
        }
        if (!\is_array($data['credentials'])) {
            throw new InvalidArgumentException('credentials must be list');
        }
        $credentials = [];
        foreach ($data['credentials'] as $credential) {
            if (!\is_array($credential)) {
                throw new InvalidArgumentException('credential entries must be objects');
            }
            /** @var array<string, mixed> $credential */
            $credentials[] = $credential;
        }
        return $credentials;
    }
}
