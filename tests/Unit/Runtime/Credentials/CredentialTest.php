<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime\Credentials;

use Arcp\Runtime\Credentials\Credential;
use PHPUnit\Framework\TestCase;

final class CredentialTest extends TestCase
{
    public function testToArrayContainsValue(): void
    {
        $credential = new Credential('cred_1', 'bearer', 'secret', 'https://llm.example');
        self::assertSame('secret', $credential->toArray()['value']);
    }

    public function testToRedactedArrayMasksValue(): void
    {
        $credential = new Credential('cred_1', 'bearer', 'secret', 'https://llm.example');
        self::assertSame('***', $credential->toRedactedArray()['value']);
    }
}
