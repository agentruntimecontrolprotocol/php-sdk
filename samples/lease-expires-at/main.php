<?php

declare(strict_types=1);

function assertLeaseActive(DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
{
    if ($now >= $expiresAt) {
        throw new RuntimeException('LEASE_EXPIRED');
    }
}

$now = new DateTimeImmutable('2026-05-22T12:00:00Z');
$expiresAt = $now->modify('+30 seconds');

assertLeaseActive($expiresAt, $now);
printf("lease active until %s\n", $expiresAt->format(DateTimeInterface::ATOM));
