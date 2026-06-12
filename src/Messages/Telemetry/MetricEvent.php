<?php

declare(strict_types=1);

namespace Arcp\Messages\Telemetry;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/** RFC §17.3 — telemetry sample. */
final readonly class MetricEvent extends MessageType
{
    /** @param array<string, bool|float|int|string> $dims */
    public function __construct(
        public string $name,
        public int|float $value,
        public string $unit,
        public array $dims = [],
    ) {
        if ($name === '' || $unit === '') {
            throw new InvalidRequestException('metric name/unit missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'metric';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['name' => $this->name, 'value' => $this->value, 'unit' => $this->unit];
        if ($this->dims !== []) {
            $out['dims'] = $this->dims;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? throw new InvalidRequestException('name missing');
        $value = $data['value'] ?? throw new InvalidRequestException('value missing');
        $unit = $data['unit'] ?? throw new InvalidRequestException('unit missing');
        if (!\is_string($name) || !\is_string($unit)) {
            throw new InvalidRequestException('name/unit must be strings');
        }
        if (!\is_int($value) && !\is_float($value)) {
            throw new InvalidRequestException('value must be numeric');
        }
        $dims = [];
        if (isset($data['dims'])) {
            if (!\is_array($data['dims'])) {
                throw new InvalidRequestException('dims must be object');
            }
            foreach ($data['dims'] as $k => $v) {
                $isScalar = \is_string($v) || \is_int($v) || \is_float($v) || \is_bool($v);
                if (!\is_string($k) || !$isScalar) {
                    throw new InvalidRequestException('dims entries must be string→scalar');
                }
                $dims[$k] = $v;
            }
        }
        return new self($name, $value, $unit, $dims);
    }
}
