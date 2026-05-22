# lease-revocation

Warehouse DB admin agent. Reads against pre-granted tables run free.
INSERT / UPDATE / DELETE / DDL trigger a synchronous
`permission.request` scoped to the specific table and operation.

## Before ARCP

Two failure modes: (1) the agent has a write-capable DB role and
operators audit Slack, hoping; (2) writes go through a separate
"approval" service that the agent doesn't actually understand —
when approval is denied, the agent gets a 403 with no structure
and either gives up or retries blindly.

## With ARCP

```php
function authorize(ARCPClient $client, string $sql, array &$leases): string
{
    $klass = classify($sql);                 // read / write / ddl
    foreach ($klass->tables as $table) {
        $leases["{$table}|{$klass->op}"] ??= requestLease(
            $client,
            "db.{$klass->op}",
            $table,
            $klass->op,
            $klass->op === 'read' ? READ_LEASE_SECONDS : WRITE_LEASE_SECONDS,
            "{$klass->op} on {$table}",
        );
    }
    return $klass->op;
}
```

Granted leases are cached. Mid-statement `lease.revoked` drops the
cache entry so the next call re-prompts.

## ARCP primitives

- Permission challenge — RFC §15.4.
- Full lease lifecycle (request, grant, use, refresh, revoke) —
  §15.5.
- `PERMISSION_DENIED` / `LEASE_EXPIRED` / `LEASE_REVOKED` — §18.2.

## File tour

- `main.php` — opens session, bootstraps reads, runs two queries.
- `sql.php` — `classify(sql)` SQL classifier stub.

## Variations

- Replace operator approval with a policy engine (Cedar, OPA) — the
  responder is interchangeable as long as it emits `lease.granted`.
- Promote read leases to row-level by encoding row-filter SQL into
  `resource` (`table:public.orders/region=us`).
- Stream every DDL into [subscriptions](../subscribe)
  for change history.
