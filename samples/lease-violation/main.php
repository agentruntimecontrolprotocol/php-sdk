<?php

declare(strict_types=1);

/**
 * @param list<string> $allowed
 *
 * @return array{ok: bool, code?: string, attempted?: string}
 */
function runLeasedOperation(array $allowed, string $operation): array
{
    if (!in_array($operation, $allowed, true)) {
        return ['ok' => false, 'code' => 'PERMISSION_DENIED', 'attempted' => $operation];
    }

    return ['ok' => true];
}

$result = runLeasedOperation(['email.read', 'email.search'], 'email.send');
printf("%s\n", json_encode($result, JSON_THROW_ON_ERROR));
