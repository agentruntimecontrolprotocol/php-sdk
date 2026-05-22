<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Runtime\Credentials;

use Arcp\Ids\JobId;
use Arcp\Runtime\Credentials\Credential;
use Arcp\Runtime\Credentials\InMemoryCredentialStore;
use PHPUnit\Framework\TestCase;

final class InMemoryCredentialStoreTest extends TestCase
{
    public function testAddRemoveForJob(): void
    {
        $store = new InMemoryCredentialStore();
        $jobId = new JobId('job_test');
        $credential = new Credential('cred_1', 'bearer', 'secret', 'memory://credentials');

        $store->add($jobId, $credential);
        self::assertSame([$credential], $store->forJob($jobId));

        $store->remove($jobId, 'cred_1');
        self::assertSame([], $store->forJob($jobId));
    }

    public function testOutstandingReportsAllJobs(): void
    {
        $store = new InMemoryCredentialStore();
        $store->add(new JobId('job_a'), new Credential('cred_a', 'bearer', 'a', 'memory://credentials'));
        $store->add(new JobId('job_b'), new Credential('cred_b', 'bearer', 'b', 'memory://credentials'));

        self::assertSame([
            ['job_id' => 'job_a', 'credential_id' => 'cred_a'],
            ['job_id' => 'job_b', 'credential_id' => 'cred_b'],
        ], $store->outstanding());
    }
}
