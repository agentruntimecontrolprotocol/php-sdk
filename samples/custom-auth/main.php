<?php

declare(strict_types=1);

/**
 * @return array{principal: string, scopes: list<string>}
 */
function verifyBearerToken(string $token): array
{
    if (!hash_equals('demo-token', $token)) {
        throw new RuntimeException('UNAUTHENTICATED');
    }

    return ['principal' => 'user:demo', 'scopes' => ['jobs:submit', 'jobs:read']];
}

$token = $argv[1] ?? '';
$auth = verifyBearerToken($token);

printf("%s %s\n", $auth['principal'], implode(',', $auth['scopes']));
