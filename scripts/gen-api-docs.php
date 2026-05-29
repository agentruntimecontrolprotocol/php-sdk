<?php

declare(strict_types=1);

// API doc generator: walks src/, extracts docblocks + signatures via PhpToken,
// emits one Markdown file per top-level namespace dir (plus index.md).
// Usage: composer docs:api  (or: php scripts/gen-api-docs.php)

$root   = dirname(__DIR__);
$srcDir = $root . '/src';
$outDir = $root . '/docs/api';

if (!is_dir($srcDir)) {
    fwrite(STDERR, "src/ not found at {$srcDir}\n");
    exit(1);
}
if (!is_dir($outDir) && !mkdir($outDir, 0o755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "failed to create {$outDir}\n");
    exit(1);
}

/** @return list<string> */
function php_files(string $dir): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

function clean_doc(string $raw): string
{
    $raw   = preg_replace('#^/\*\*|\*/$#', '', trim($raw)) ?? '';
    $lines = preg_split('/\R/', $raw) ?: [];
    $out   = [];
    foreach ($lines as $l) {
        $out[] = rtrim(preg_replace('/^\s*\*\s?/', '', $l) ?? $l);
    }
    return trim(implode("\n", $out));
}

/** @return array{namespace:string,decls:list<array<string,mixed>>} */
function parse_file(string $path): array
{
    $tokens = PhpToken::tokenize(file_get_contents($path) ?: '');
    $ns     = '';
    $decls  = [];
    $doc    = null;
    $n      = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];

        if ($t->id === T_DOC_COMMENT) {
            $doc = clean_doc($t->text);
            continue;
        }
        if ($t->id === T_WHITESPACE || $t->id === T_COMMENT) {
            continue;
        }

        if ($t->id === T_NAMESPACE) {
            $name = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $tj = $tokens[$j];
                if (in_array($tj->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name .= $tj->text;
                } elseif ($tj->text === ';' || $tj->text === '{') {
                    break;
                }
            }
            $ns  = trim($name, '\\');
            $doc = null;
            continue;
        }

        if (in_array($t->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            // skip `new class { ... }`
            $p = $i - 1;
            while ($p >= 0 && $tokens[$p]->id === T_WHITESPACE) {
                $p--;
            }
            if ($p >= 0 && $tokens[$p]->id === T_NEW) {
                continue;
            }
            $kind = str_replace('t_', '', strtolower(token_name($t->id)));
            $name = '';
            for ($j = $i + 1; $j < $n; $j++) {
                if ($tokens[$j]->id === T_STRING) {
                    $name = $tokens[$j]->text;
                    break;
                }
            }
            if ($name === '') {
                continue;
            }
            $decls[] = ['kind' => $kind, 'name' => $name, 'doc' => $doc ?? '', 'members' => []];
            $doc     = null;
            continue;
        }

        if ($t->id === T_FUNCTION) {
            // collect leading modifiers
            $mods = [];
            $b    = $i - 1;
            while ($b >= 0) {
                $tb = $tokens[$b];
                if ($tb->id === T_WHITESPACE) {
                    $b--;
                    continue;
                }
                if (in_array($tb->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                    $mods[] = strtolower($tb->text);
                    $b--;
                    continue;
                }
                break;
            }
            $mods = array_reverse($mods);

            // function name
            $j = $i + 1;
            while ($j < $n && $tokens[$j]->id === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $n || $tokens[$j]->id !== T_STRING) {
                $doc = null;
                continue; // closure / arrow fn
            }
            $fname = $tokens[$j]->text;

            // params
            $k = $j + 1;
            while ($k < $n && $tokens[$k]->text !== '(') {
                $k++;
            }
            $depth  = 0;
            $params = '';
            for (; $k < $n; $k++) {
                $params .= $tokens[$k]->text;
                if ($tokens[$k]->text === '(') {
                    $depth++;
                } elseif ($tokens[$k]->text === ')' && --$depth === 0) {
                    $k++;
                    break;
                }
            }
            // return type until { or ;
            $ret = '';
            for (; $k < $n; $k++) {
                if ($tokens[$k]->text === '{' || $tokens[$k]->text === ';') {
                    break;
                }
                $ret .= $tokens[$k]->text;
            }
            $sig = preg_replace('/\s+/', ' ', trim(implode(' ', $mods) . ' function ' . $fname . $params . $ret));

            if (!empty($decls)) {
                $decls[count($decls) - 1]['members'][] = ['name' => $fname, 'sig' => $sig, 'doc' => $doc ?? ''];
            } else {
                $decls[] = ['kind' => 'function', 'name' => $fname, 'sig' => $sig, 'doc' => $doc ?? '', 'members' => []];
            }
            $doc = null;
        }
    }

    return ['namespace' => $ns, 'decls' => $decls];
}

function render_decl(array $d): string
{
    $out = "### `" . $d['kind'] . ' ' . $d['name'] . "`\n\n";
    if (!empty($d['doc'])) {
        $out .= $d['doc'] . "\n\n";
    }
    if ($d['kind'] === 'function' && isset($d['sig'])) {
        $out .= "```php\n" . $d['sig'] . "\n```\n\n";
    }
    foreach ($d['members'] as $m) {
        $out .= "#### `" . $m['name'] . "`\n\n```php\n" . $m['sig'] . "\n```\n\n";
        if (!empty($m['doc'])) {
            $out .= $m['doc'] . "\n\n";
        }
    }
    return $out;
}

// Group by top-level namespace dir under src/
$files     = php_files($srcDir);
$groups    = [];
$rootDecls = [];
foreach ($files as $path) {
    $rel    = substr($path, strlen($srcDir) + 1);
    $parsed = parse_file($path);
    $parsed['_rel'] = $rel;
    if (str_contains($rel, '/')) {
        $groups[explode('/', $rel, 2)[0]][] = $parsed;
    } else {
        $rootDecls[] = $parsed;
    }
}
ksort($groups);

$index  = "# PHP SDK API Reference\n\n";
$index .= "Auto-generated from PHPDoc blocks in `src/`. Regenerate with `composer docs:api`.\n\n";
$index .= "## Namespaces\n\n";

foreach ($groups as $top => $entries) {
    $slug = strtolower($top);
    usort($entries, fn ($a, $b) => strcmp($a['_rel'], $b['_rel']));
    $content = "# Arcp\\{$top}\n\n";
    foreach ($entries as $e) {
        if (empty($e['decls'])) {
            continue;
        }
        $content .= "## `" . $e['namespace'] . "` &mdash; `" . $e['_rel'] . "`\n\n";
        foreach ($e['decls'] as $d) {
            $content .= render_decl($d);
        }
    }
    file_put_contents($outDir . '/' . $slug . '.md', $content);
    $index .= "- [Arcp\\{$top}](./{$slug}.md)\n";
}

if (!empty($rootDecls)) {
    $content = "# Arcp (root)\n\n";
    foreach ($rootDecls as $e) {
        if (empty($e['decls'])) {
            continue;
        }
        $content .= "## `" . $e['namespace'] . "` &mdash; `" . $e['_rel'] . "`\n\n";
        foreach ($e['decls'] as $d) {
            $content .= render_decl($d);
        }
    }
    file_put_contents($outDir . '/_root.md', $content);
    $index .= "- [Arcp (root)](./_root.md)\n";
}

file_put_contents($outDir . '/index.md', $index);
$count = count($groups) + (empty($rootDecls) ? 0 : 1) + 1;
echo "Wrote {$count} markdown files to docs/api/\n";
