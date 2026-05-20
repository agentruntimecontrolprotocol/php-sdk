<?php

declare(strict_types=1);

use Arcp\Client\ARCPClient;

require __DIR__ . '/../../vendor/autoload.php';

function elidedClient(): ARCPClient
{
    throw new RuntimeException('client setup elided');
}

$client = elidedClient();

$page = $client->listJobs(['agent' => 'reporter', 'status' => ['running']], limit: 10);
foreach ($page->jobs as $job) {
    $jobId = $job['job_id'] ?? '?';
    $agent = $job['agent'] ?? '?';
    $status = $job['status'] ?? '?';
    printf(
        "%s %s %s\n",
        is_scalar($jobId) ? (string) $jobId : '?',
        is_scalar($agent) ? (string) $agent : '?',
        is_scalar($status) ? (string) $status : '?',
    );
}

if ($page->nextCursor !== null) {
    $next = $client->listJobs(['agent' => 'reporter'], limit: 10, cursor: $page->nextCursor);
    printf("next page: %d jobs\n", count($next->jobs));
}
