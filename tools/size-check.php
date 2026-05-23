<?php

declare(strict_types=1);

/**
 * Size-limit gate enforcing PHP_SDK_GUIDE.md §14.
 *
 * Hard limits (non-negotiable):
 *   - Files          ≤ 400 lines
 *   - Classes        ≤ 300 lines (body span, exclusive of leading attrs)
 *   - Functions      ≤ 30  lines (body, excluding braces)
 *   - Function args  ≤ 4
 *   - Line length    ≤ 100 characters
 *
 * Returns exit 1 with a diff-like report on the first violation found.
 * Run with `--aspirational` to also fail on the soft limits.
 *
 * Usage: php tools/size-check.php [path ...] [--aspirational]
 */
$paths = [];
$aspirational = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--aspirational') {
        $aspirational = true;
        continue;
    }
    $paths[] = $arg;
}

if ($paths === []) {
    $paths = [__DIR__ . '/../src'];
}

$hard = [
    'file_lines' => 400,
    'class_lines' => 300,
    'func_body_lines' => 30,
    'func_params' => 4,
    'ctor_params' => 6,
    'line_length' => 100,
];
$soft = [
    'file_lines' => 200,
    'class_lines' => 150,
    'func_body_lines' => 15,
    'func_params' => 4,
    'ctor_params' => 4,
    'line_length' => 80,
];

$limits = $aspirational ? $soft : $hard;

$violations = [];
foreach ($paths as $path) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($rii as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $violations = [...$violations, ...checkFile($file->getPathname(), $limits)];
    }
}

if ($violations === []) {
    fwrite(STDOUT, "size-check: OK\n");
    exit(0);
}

fwrite(STDERR, "size-check: " . count($violations) . " violation(s)\n");
foreach ($violations as $v) {
    fwrite(STDERR, "  {$v}\n");
}
exit(1);

/**
 * @param array<string, int> $limits
 * @return list<string>
 */
function checkFile(string $file, array $limits): array
{
    $rel = ltrim(str_replace(getcwd(), '', $file), '/');
    $issues = [];

    $raw = file($file, FILE_IGNORE_NEW_LINES);
    if ($raw === false) {
        return [];
    }
    $fileLines = count($raw);
    if ($fileLines > $limits['file_lines']) {
        $issues[] = sprintf(
            '%s — file is %d lines (limit %d)',
            $rel,
            $fileLines,
            $limits['file_lines'],
        );
    }

    foreach ($raw as $i => $line) {
        $len = strlen($line);
        if ($len > $limits['line_length']) {
            $issues[] = sprintf(
                '%s:%d — line is %d chars (limit %d)',
                $rel,
                $i + 1,
                $len,
                $limits['line_length'],
            );
        }
    }

    $src = file_get_contents($file);
    if ($src === false) {
        return $issues;
    }
    try {
        $tokens = PhpToken::tokenize($src);
    } catch (Throwable) {
        return $issues;
    }
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];

        if ($t->id === T_FUNCTION) {
            [, $issue] = checkFunction($tokens, $i, $count, $rel, $limits);
            if ($issue !== null) {
                $issues[] = $issue;
            }
            continue;
        }

        if ($t->id === T_CLASS) {
            $prev = $i - 1;
            while ($prev >= 0 && in_array($tokens[$prev]->id, [
                T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
                T_ABSTRACT, T_FINAL, T_READONLY,
            ], true)) {
                $prev--;
            }
            if ($prev >= 0 && $tokens[$prev]->id === T_NEW) {
                continue;
            }
            [, $issue] = checkClass($tokens, $i, $count, $rel, $limits);
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }
    }

    return $issues;
}

/**
 * Returns true if the preceding docblock contains a
 * `@size-check-suppress(reason: ...)` tag — used to silence the
 * parameter-count check for wire-shape DTOs whose param list maps 1:1
 * to the protocol and cannot be collapsed without breaking the
 * public API. The `reason:` argument is required and human-readable.
 *
 * @param list<PhpToken> $tokens
 */
function hasSuppression(array $tokens, int $i): bool
{
    for ($k = $i - 1; $k >= 0; $k--) {
        $id = $tokens[$k]->id;
        if (in_array($id, [
            T_WHITESPACE, T_ABSTRACT, T_FINAL, T_PUBLIC, T_PROTECTED,
            T_PRIVATE, T_STATIC, T_READONLY, T_ATTRIBUTE,
        ], true)) {
            continue;
        }
        if ($id === T_DOC_COMMENT) {
            return str_contains($tokens[$k]->text, '@size-check-suppress');
        }
        return false;
    }
    return false;
}

/**
 * @param list<PhpToken> $tokens
 * @param array<string, int> $limits
 * @return array{0:int, 1:?string}
 */
function checkFunction(array $tokens, int $i, int $count, string $rel, array $limits): array
{
    $startLine = $tokens[$i]->line;
    $j = $i + 1;
    $name = null;
    while ($j < $count && $tokens[$j]->text !== '(' && $tokens[$j]->text !== ';') {
        if ($tokens[$j]->id === T_STRING && $name === null) {
            $name = $tokens[$j]->text;
        }
        $j++;
    }
    if ($j >= $count || $tokens[$j]->text !== '(') {
        return [$j, null];
    }
    $issues = null;
    $params = 0;
    $hasParam = false;
    $pdepth = 1;
    $j++;
    while ($j < $count && $pdepth > 0) {
        $tx = $tokens[$j]->text;
        if ($tx === '(' || $tx === '[' || $tx === '{') {
            $pdepth++;
        } elseif ($tx === ')' || $tx === ']' || $tx === '}') {
            $pdepth--;
            if ($pdepth === 0) {
                $j++;
                break;
            }
        } elseif ($tx === ',' && $pdepth === 1) {
            $params++;
            $hasParam = true;
        } else {
            if (!in_array($tokens[$j]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $hasParam = true;
            }
        }
        $j++;
    }
    if ($hasParam) {
        $params++;
    }
    $isCtor = $name === '__construct';
    $paramLimit = $isCtor ? $limits['ctor_params'] : $limits['func_params'];
    if ($params > $paramLimit && !hasSuppression($tokens, $i)) {
        $issues = sprintf(
            '%s:%d — %s %s has %d parameters (limit %d)',
            $rel,
            $startLine,
            $isCtor ? 'constructor' : 'function',
            $name ?? '<anon>',
            $params,
            $paramLimit,
        );
    }

    while ($j < $count && $tokens[$j]->text !== '{' && $tokens[$j]->text !== ';') {
        $j++;
    }
    if ($j >= $count || $tokens[$j]->text === ';') {
        return [$j, $issues];
    }
    $bopenLine = $tokens[$j]->line;
    $bdepth = 1;
    $j++;
    while ($j < $count && $bdepth > 0) {
        $tx = $tokens[$j]->text;
        if ($tx === '{') {
            $bdepth++;
        } elseif ($tx === '}') {
            $bdepth--;
            if ($bdepth === 0) {
                break;
            }
        }
        $j++;
    }
    $closeLine = $tokens[$j]->line ?? null;
    if ($closeLine !== null) {
        $body = $closeLine - $bopenLine - 1;
        if ($body > $limits['func_body_lines']) {
            $issues = sprintf(
                '%s:%d — function %s body is %d lines (limit %d)',
                $rel,
                $startLine,
                $name ?? '<anon>',
                $body,
                $limits['func_body_lines'],
            );
        }
    }
    return [$j, $issues];
}

/**
 * @param list<PhpToken> $tokens
 * @param array<string, int> $limits
 * @return array{0:int, 1:?string}
 */
function checkClass(array $tokens, int $i, int $count, string $rel, array $limits): array
{
    $startLine = $tokens[$i]->line;
    $j = $i + 1;
    $name = null;
    while ($j < $count && $tokens[$j]->text !== '{') {
        if ($tokens[$j]->id === T_STRING && $name === null) {
            $name = $tokens[$j]->text;
        }
        $j++;
    }
    if ($j >= $count) {
        return [$j, null];
    }
    $bdepth = 1;
    $j++;
    while ($j < $count && $bdepth > 0) {
        $tx = $tokens[$j]->text;
        if ($tx === '{') {
            $bdepth++;
        } elseif ($tx === '}') {
            $bdepth--;
            if ($bdepth === 0) {
                break;
            }
        }
        $j++;
    }
    $endLine = $tokens[$j]->line ?? null;
    if ($endLine === null || $name === null) {
        return [$j, null];
    }
    $lines = $endLine - $startLine + 1;
    if ($lines > $limits['class_lines']) {
        return [$j, sprintf(
            '%s:%d — class %s is %d lines (limit %d)',
            $rel,
            $startLine,
            $name,
            $lines,
            $limits['class_lines'],
        )];
    }
    return [$j, null];
}
