<?php

declare(strict_types=1);

namespace Arcp;

/**
 * Protocol and implementation version constants.
 *
 * RFC §6.1 — the protocol version is included in every envelope as the
 * `arcp` field; the implementation version is reported via runtime/client
 * identity blocks in `session.open` / `session.accepted` (RFC §8.2, §8.3).
 */
final class Version
{
    /** Wire-protocol version understood by this implementation (RFC §6.1). */
    public const string PROTOCOL_VERSION = '1.0';

    /** SemVer of this PHP SDK implementation. */
    public const string IMPL_VERSION = '0.1.0-dev';

    /** Identifier reported in `client.kind` / `runtime.kind` (RFC §8.2/§8.3). */
    public const string IMPL_KIND = 'arcp-php';

    private function __construct()
    {
    }
}
