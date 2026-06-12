<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Errors\InvalidArgumentException;

/**
 * Parsed ARCP v1.1 agent reference: `name` or `name@version`.
 *
 * Per §7.5 the grammar is `name ::= [a-z0-9][a-z0-9._-]*` — lowercase
 * only — so {@see \Arcp\Runtime\ARCPRuntime::registerTool()} rejects
 * uppercase names at registration rather than minting non-interoperable
 * agent identities.
 */
final readonly class AgentRef
{
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';
    private const string VERSION_PATTERN = '/^[A-Za-z0-9.+_-]+$/';

    public function __construct(public string $name, public ?string $version = null)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('invalid agent name: ' . $name);
        }
        if ($version !== null && preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new InvalidArgumentException('invalid agent version: ' . $version);
        }
    }

    public static function parse(string $input): self
    {
        $at = strpos($input, '@');
        if ($at === false) {
            return new self($input);
        }
        return new self(substr($input, 0, $at), substr($input, $at + 1));
    }

    public function toString(): string
    {
        return $this->version === null ? $this->name : $this->name . '@' . $this->version;
    }
}
