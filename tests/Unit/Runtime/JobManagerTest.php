<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime;

use Arcp\Clock\FakeClock;
use Arcp\Envelope\Envelope;
use Arcp\Ids\MessageId;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Runtime\JobManager;
use Arcp\Runtime\JobState;
use Arcp\Runtime\Session;
use Arcp\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class JobManagerTest extends TestCase
{
    private function session(): Session
    {
        [$a] = MemoryTransport::pair();

        return new Session($a);
    }

    private function invocation(FakeClock $clock): Envelope
    {
        return new Envelope(
            id: MessageId::random(),
            payload: new ToolInvoke('demo', []),
            timestamp: $clock->now(),
        );
    }

    public function testAllAndCountDoNotEvictTerminalEntries(): void
    {
        $clock = new FakeClock();
        $jobs = new JobManager($clock, terminalRetentionSeconds: 10);
        $job = $jobs->start($this->session(), $this->invocation($clock), 'demo');
        $jobs->transition($job, JobState::Completed);

        // Push past the retention window, then read via the pure accessors.
        $clock->advance(30);
        self::assertSame([], $jobs->all());
        self::assertSame(0, $jobs->count());

        // The entry must still be retrievable directly: read accessors are
        // pure and never unset map entries (#83).
        self::assertNotNull($jobs->tryGet($job->id));
    }

    public function testCancelTransitionsToCancelling(): void
    {
        $clock = new FakeClock();
        $jobs = new JobManager($clock);
        $job = $jobs->start($this->session(), $this->invocation($clock), 'demo');
        $jobs->transition($job, JobState::Running);

        self::assertTrue($jobs->cancel($job->id));
        self::assertSame(JobState::Cancelling, $job->state);
        // A second cancel is a no-op now that the request is in flight.
        self::assertFalse($jobs->cancel($job->id));
    }
}
