<?php

declare(strict_types=1);

/**
 * @param list<string> $leases
 *
 * @return array{ok: bool, code?: string}
 */
function requireLease(array $leases, string $operation): array
{
    foreach ($leases as $lease) {
        if (str_starts_with($lease, $operation . ':')) {
            return ['ok' => true];
        }
    }

    return ['ok' => false, 'code' => 'PERMISSION_DENIED'];
}

$leases = ['email.search:support@example.com', 'email.read:support@example.com'];
$sendAttempt = requireLease($leases, 'email.send');
$vendorEvent = ['type' => 'arcpx.acme.email.parsed.v1', 'message_id' => 'msg_123'];

print json_encode(['send' => $sendAttempt, 'event' => $vendorEvent], JSON_THROW_ON_ERROR) . "\n";
