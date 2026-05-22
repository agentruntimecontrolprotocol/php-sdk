<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Messages\Execution;

use Arcp\Messages\Execution\JobAccepted;
use PHPUnit\Framework\TestCase;

final class JobAcceptedTest extends TestCase
{
    public function testRoundTripWithCredentials(): void
    {
        $msg = new JobAccepted(credentials: [[
            'id' => 'cred_1',
            'scheme' => 'bearer',
            'value' => 'secret',
            'endpoint' => 'https://llm.example',
        ]]);

        self::assertEquals($msg, JobAccepted::fromArray($msg->toArray()));
    }

    public function testRedactedMasksCredentialValue(): void
    {
        $msg = new JobAccepted(credentials: [[
            'id' => 'cred_1',
            'scheme' => 'bearer',
            'value' => 'secret',
            'endpoint' => 'https://llm.example',
        ]]);

        self::assertSame('***', $msg->redacted()->credentials[0]['value'] ?? null);
    }
}
