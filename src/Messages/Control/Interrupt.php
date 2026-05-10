<?php

declare(strict_types=1);

namespace Arcp\Messages\Control;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidArgumentException;

/** RFC §10.5 — pause a running job and request human guidance. */
final readonly class Interrupt extends MessageType
{
    public function __construct(
        public string $target,
        public string $targetId,
        public string $prompt = '',
    ) {
        if ($target === '') {
            throw new InvalidArgumentException('interrupt.target missing');
        }
        if ($targetId === '') {
            throw new InvalidArgumentException('interrupt.target_id missing');
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'interrupt';
    }

    #[\Override]
    public function toArray(): array
    {
        $out = ['target' => $this->target, 'target_id' => $this->targetId];
        if ($this->prompt !== '') {
            $out['prompt'] = $this->prompt;
        }
        return $out;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $target = $data['target'] ?? throw new InvalidArgumentException('target missing');
        $tid = $data['target_id'] ?? throw new InvalidArgumentException('target_id missing');
        if (!\is_string($target) || !\is_string($tid)) {
            throw new InvalidArgumentException('target/target_id must be strings');
        }
        $prompt = '';
        if (isset($data['prompt']) && \is_string($data['prompt'])) {
            $prompt = $data['prompt'];
        }
        return new static($target, $tid, $prompt);
    }
}
