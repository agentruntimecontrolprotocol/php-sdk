<?php

declare(strict_types=1);

/**
 * @return array{tool: string, lease: array<string, list<string>>}
 */
function submitTriageJob(string $mailbox): array
{
    return [
        'tool' => 'triage',
        'lease' => [
            'email' => ['email.search:' . $mailbox, 'email.read:' . $mailbox],
        ],
    ];
}

print json_encode(submitTriageJob('support@example.com'), JSON_THROW_ON_ERROR) . "\n";
