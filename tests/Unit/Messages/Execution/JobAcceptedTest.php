<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Messages\Execution;

use Arcp\Ids\JobId;
use Arcp\Messages\Execution\JobAccepted;
use PHPUnit\Framework\TestCase;

final class JobAcceptedTest extends TestCase
{
    public function testRoundTripWithCredentials(): void
    {
        $msg = new JobAccepted(
            jobId: new JobId('job_x'),
            agent: 'planner@1.0.0',
            budget: ['USD' => 5.0],
            credentials: [[
                'id' => 'cred_1',
                'scheme' => 'bearer',
                'value' => 'secret',
                'endpoint' => 'https://llm.example',
            ]],
            acceptedAt: new \DateTimeImmutable('2026-05-13T19:30:00.000+00:00'),
        );

        self::assertEquals($msg, JobAccepted::fromArray($msg->toArray()));
    }

    public function testRedactedMasksCredentialValue(): void
    {
        $msg = new JobAccepted(
            jobId: new JobId('job_x'),
            agent: 'planner@1.0.0',
            credentials: [[
                'id' => 'cred_1',
                'scheme' => 'bearer',
                'value' => 'secret',
                'endpoint' => 'https://llm.example',
            ]],
        );

        self::assertSame('***', $msg->redacted()->credentials[0]['value'] ?? null);
    }
}
