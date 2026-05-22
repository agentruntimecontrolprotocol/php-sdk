<?php

declare(strict_types=1);

/**
 * @return array{cmd: list<string>, transport: string}
 */
function spawnRuntimeCommand(string $script): array
{
    return ['cmd' => [PHP_BINARY, $script], 'transport' => 'stdio'];
}

$runtime = spawnRuntimeCommand('bin/runtime.php');
printf("%s %s\n", $runtime['transport'], implode(' ', $runtime['cmd']));
