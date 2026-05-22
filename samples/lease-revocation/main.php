<?php

declare(strict_types=1);

/*
 * lease-revocation — warehouse DB admin agent. Reads pre-granted;
 * writes prompt operator. `lease.revoked` mid-flight drops the cache so
 * the next call re-prompts.
 *
 * RFC §15.4 (permission challenge), §15.5 (lease lifecycle, revocation).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/sql.php';

use Arcp\Client\ARCPClient;
use Arcp\Envelope\Envelope;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Messages\Permissions\LeaseRevoked;

use function Arcp\Samples\LeaseRevocation\classify;

const PRE_GRANTED = [
    'public.orders',
    'public.customers',
    'warehouse.fct_revenue_daily',
];
const READ_LEASE_SECONDS = 60 * 60;
const WRITE_LEASE_SECONDS = 5 * 60;

/**
 * @return array{0: string, 1: \DateTimeImmutable} (lease_id, expires_at)
 */
function requestLease(
    ARCPClient $client,
    string $permission,
    string $table,
    string $operation,
    int $seconds,
    string $reason,
): array {
    // Real impl: send `permission.request`, await `lease.granted` /
    // `permission.deny`. PermissionDenyException raised on deny.
    throw new \RuntimeException('not implemented');
}

/**
 * @param array<string, array{0: string, 1: \DateTimeImmutable}> $leases keyed "table|op"
 */
function authorize(ARCPClient $client, string $sql, array &$leases): string
{
    $klass = classify($sql);
    if ($klass->tables === []) {
        throw new InvalidArgumentException('no table referenced');
    }
    $op = $klass->op; // "read" / "write" / "ddl"
    $seconds = $op === 'read' ? READ_LEASE_SECONDS : WRITE_LEASE_SECONDS;
    $now = new \DateTimeImmutable('now');
    foreach ($klass->tables as $table) {
        $key = "{$table}|{$op}";
        $cached = $leases[$key] ?? null;
        if ($cached !== null && $cached[1] > $now) {
            continue;
        }
        $leases[$key] = requestLease(
            $client,
            "db.{$op}",
            $table,
            $op,
            $seconds,
            sprintf('%s on %s: %s', strtoupper($op), $table, substr($sql, 0, 80)),
        );
    }
    return $op;
}

/**
 * @param array<string, array{0: string, 1: \DateTimeImmutable}> $leases
 */
function handleInbound(Envelope $env, array &$leases): void
{
    $msg = $env->payload;
    if ($msg instanceof LeaseRevoked) {
        $lid = (string) $msg->leaseId;
        foreach ($leases as $k => $v) {
            if ($v[0] === $lid) {
                unset($leases[$k]);
            }
        }
    }
}

function main(): void
{
    /** @var ARCPClient $client */
    $client = elided(); // transport, identity, auth elided

    /** @var array<string, array{0: string, 1: \DateTimeImmutable}> $leases */
    $leases = [];

    // Subscribe to lease lifecycle events; revocations drop cache entries.
    $client->subscribe(
        ['types' => ['lease.revoked', 'lease.extended']],
        static function (Envelope $env) use (&$leases): void {
            handleInbound($env, $leases);
        },
    );

    // Pre-grant the broad reads at session open. From here on, SELECT
    // against these tables runs free.
    foreach (PRE_GRANTED as $table) {
        $leases["{$table}|read"] = requestLease(
            $client,
            'db.read',
            $table,
            'read',
            READ_LEASE_SECONDS,
            'bootstrap',
        );
    }

    // SELECT — covered by the bootstrap lease.
    authorize(
        $client,
        'SELECT count(*) FROM public.orders WHERE shipped_at::date = current_date - 1',
        $leases,
    );
    // UPDATE — triggers permission.request; operator must approve.
    authorize(
        $client,
        "UPDATE public.orders SET status='refunded' WHERE id=4812",
        $leases,
    );

    $client->close();
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
