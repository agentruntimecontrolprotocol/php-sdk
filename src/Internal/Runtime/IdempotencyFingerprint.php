<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Messages\Execution\JobSubmit;

/**
 * §7.2 idempotency fingerprint: a collision-resistant SHA-256 over the
 * semantically meaningful `job.submit` fields (agent, input,
 * lease_request, lease_constraints, max_runtime_sec).
 *
 * The serialization is canonical — object keys are sorted recursively —
 * so two payloads that differ only in JSON object key order hash
 * identically. List order is significant and preserved.
 *
 * @internal
 */
final class IdempotencyFingerprint
{
    private function __construct()
    {
    }

    public static function of(JobSubmit $submit): string
    {
        $canon = [
            'agent' => $submit->agent,
            'input' => self::canonicalize($submit->input),
        ];
        if ($submit->leaseRequest !== null) {
            $canon['lease_request'] = self::canonicalize($submit->leaseRequest);
        }
        if ($submit->leaseConstraints !== null) {
            $canon['lease_constraints'] = self::canonicalize($submit->leaseConstraints);
        }
        if ($submit->maxRuntimeSec !== null) {
            $canon['max_runtime_sec'] = $submit->maxRuntimeSec;
        }
        return hash('sha256', json_encode($canon, JSON_THROW_ON_ERROR));
    }

    /**
     * Recursively sort string-keyed maps; keep list order intact.
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }
        if (!array_is_list($out)) {
            ksort($out);
        }
        return $out;
    }
}
