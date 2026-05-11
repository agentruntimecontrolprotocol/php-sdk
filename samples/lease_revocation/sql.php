<?php

declare(strict_types=1);

namespace Arcp\Samples\LeaseRevocation;

/** SQL classifier — sqlglot-equivalent (e.g., greenlion/php-sql-parser) in production. */
final class StatementClass
{
    /** @param list<string> $tables */
    public function __construct(
        public readonly string $op, // "read" | "write" | "ddl"
        public readonly array $tables,
    ) {
    }
}

function classify(string $sql): StatementClass
{
    throw new \RuntimeException('not implemented');
}
