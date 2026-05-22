<?php

declare(strict_types=1);

namespace Arcp\Runtime\Credentials;

final readonly class CredentialRotation
{
    public function __construct(
        public string $id,
        public string $value,
    ) {
    }

    /** @return array{id: string, value: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'value' => $this->value];
    }
}
