<?php

declare(strict_types=1);

namespace Arcp\Extensions;

use Arcp\Errors\InvalidArgumentException;

/**
 * Validation for ARCP extension type/field names (RFC §21.1).
 *
 * Accepted forms:
 *  - `arcpx.<vendor-or-domain>.<name>.v<n>` (preferred).
 *  - Reverse-DNS prefix (`com.acme.workflow.v2`).
 *
 * The bare `x-` prefix is reserved for **transport-internal** experimental
 * fields and MUST NOT appear as a long-lived envelope type. We accept it
 * only on `extensions.<x-foo>` keys, never as message-type names.
 *
 * Core message-type prefixes (`session.`, `tool.`, `job.`, `stream.`,
 * `human.`, `permission.`, `lease.`, `subscribe`, `unsubscribe`,
 * `artifact.`, `event.emit`, `log`, `metric`, `trace.span`, `ping`,
 * `pong`, `ack`, `nack`, `cancel`, `interrupt`, `resume`, `backpressure`,
 * `checkpoint.`, `workflow.`, `agent.`) are reserved and rejected here.
 */
final class ExtensionNamespace
{
    /** @var list<string> */
    private const array CORE_PREFIXES = [
        'session.', 'tool.', 'job.', 'stream.',
        'human.', 'permission.', 'lease.',
        'subscribe', 'subscribe.', 'unsubscribe', 'unsubscribe.', 'artifact.',
        'event.emit', 'log', 'metric', 'trace.span',
        'ping', 'pong', 'ack', 'nack',
        'cancel', 'interrupt', 'resume', 'backpressure',
        'checkpoint.', 'workflow.', 'agent.',
    ];

    private function __construct()
    {
    }

    /** True iff `$type` matches one of the core prefixes (RFC §21.3). */
    public static function isCore(string $type): bool
    {
        foreach (self::CORE_PREFIXES as $prefix) {
            if (str_ends_with($prefix, '.')) {
                if (str_starts_with($type, $prefix)) {
                    return true;
                }
            } elseif ($type === $prefix) {
                return true;
            }
        }
        return false;
    }

    /** True iff `$type` is a syntactically valid extension type-name. */
    public static function isValidExtension(string $type): bool
    {
        if (self::isCore($type)) {
            return false;
        }
        // The bare `x-` prefix is reserved for transport-internal
        // experimental fields and MUST NOT be a long-lived envelope type.
        if (str_starts_with($type, 'x-')) {
            return false;
        }
        // arcpx.<vendor>.<name>.v<n>  e.g. arcpx.example.v1
        // reverse-DNS prefix   e.g. com.acme.workflow.v2
        return preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*)+$/', $type) === 1;
    }

    /**
     * Reject a name with a structured error. Throws
     * {@see \Arcp\Errors\InvalidArgumentException} so the caller can lift
     * it onto a `nack` envelope.
     */
    public static function ensureValidExtension(string $type): void
    {
        if (!self::isValidExtension($type)) {
            throw new InvalidArgumentException(
                \sprintf('not a valid ARCP extension type: %s', $type),
                ['type' => $type],
            );
        }
    }
}
