<?php

declare(strict_types=1);

namespace Arcp\Extensions;

/**
 * Tracks the extensions advertised by a session (RFC §7, §21.2).
 *
 * Both client and runtime hold one of these per active session: the client
 * learns which extensions the runtime supports; the runtime tracks what
 * the client requested. Filters and dispatch consult this registry to
 * decide whether to deliver, drop, or `nack` an unknown-type message
 * (RFC §21.3).
 */
final class ExtensionRegistry
{
    /** @var array<string, true> */
    private array $advertised = [];

    /**
     * @param iterable<string> $extensions Initial extensions advertised
     *                                     by the local end. Each is
     *                                     validated against {@see
     *                                     ExtensionNamespace::isValidExtension()}.
     */
    public function __construct(iterable $extensions = [])
    {
        foreach ($extensions as $ext) {
            $this->advertise($ext);
        }
    }

    public function advertise(string $extension): void
    {
        ExtensionNamespace::ensureValidExtension($extension);
        $this->advertised[$extension] = true;
    }

    public function isAdvertised(string $extension): bool
    {
        return isset($this->advertised[$extension]);
    }

    /**
     * Decide what to do with an inbound message of `$type` per RFC §21.3.
     *
     *  - Core type → `core` (handler must exist or `nack UNIMPLEMENTED`).
     *  - Advertised extension → `advertised`.
     *  - Unadvertised extension, marked optional → `drop`.
     *  - Unadvertised extension, mandatory       → `nack`.
     *  - Anything else (malformed namespace)     → `nack`.
     *
     * @return 'core'|'advertised'|'drop'|'nack'
     */
    public function dispositionFor(string $type, bool $optional): string
    {
        if (ExtensionNamespace::isCore($type)) {
            return 'core';
        }
        if (!ExtensionNamespace::isValidExtension($type)) {
            return 'nack';
        }
        if ($this->isAdvertised($type)) {
            return 'advertised';
        }
        return $optional ? 'drop' : 'nack';
    }

    /** @return list<string> */
    public function listAdvertised(): array
    {
        return array_keys($this->advertised);
    }
}
