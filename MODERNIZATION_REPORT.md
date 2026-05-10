# MODERNIZATION_REPORT

Generated: 2026-05-10 (America/New_York)
Repository: `/Users/nficano/code/arpc/php-sdk`
Phase: 0 (Discovery, read-only)

## Scope and safety notes
- Current branch: `main`.
- Working tree is not clean (pre-existing modified/untracked files).
- Per protocol, no source code modernization edits were made in this phase.

## Discovery Checklist Output (Verbatim)

### 1) Environment / shape / tooling / baselines / version targets

```text
PHP 8.4.21 (cli) (built: May  5 2026 16:34:12) (NTS)
Copyright (c) The PHP Group
Built by Homebrew
Zend Engine v4.4.21, Copyright (c) Zend Technologies
    with Zend OPcache v8.4.21, Copyright (c), by Zend Technologies
Composer version 2.9.7 2026-04-14 13:31:52
PHP version 8.4.21 (/opt/homebrew/Cellar/php@8.4/8.4.21/bin/php)
Run the "diagnose" command to get more detailed diagnostics output.
{
  "name": "arcp/arcp",
  "type": "library",
  "require": {
    "php": ">=8.4",
    "ext-pdo": "*",
    "ext-pdo_sqlite": "*",
    "ext-mbstring": "*",
    "ext-json": "*",
    "amphp/amp": "^3.0",
    "amphp/pipeline": "^1.0",
    "amphp/socket": "^2.0",
    "amphp/websocket": "^2.0",
    "amphp/websocket-client": "^2.0",
    "amphp/websocket-server": "^4.0",
    "amphp/byte-stream": "^2.0",
    "amphp/process": "^2.0",
    "amphp/sync": "^2.0",
    "revolt/event-loop": "^1.0",
    "psr/log": "^3.0",
    "firebase/php-jwt": "^7.0",
    "symfony/uid": "^7.0",
    "justinrainbow/json-schema": "^6.0",
    "symfony/console": "^7.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0",
    "phpstan/phpstan": "^2.0",
    "phpstan/phpstan-strict-rules": "^2.0",
    "vimeo/psalm": "^6.0 || ^5.0 || dev-master",
    "friendsofphp/php-cs-fixer": "^3.0",
    "monolog/monolog": "^3.0"
  },
  "autoload": {
    "psr-4": {
      "Arcp\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Arcp\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "lint": "php-cs-fixer fix --dry-run --diff",
    "format": "php-cs-fixer fix",
    "stan": "phpstan analyze --memory-limit=512M",
    "psalm": "psalm --no-cache",
    "test": "phpunit --testdox",
    "coverage": "phpunit --coverage-text --coverage-clover=coverage.xml",
    "gates": [
      "@lint",
      "@stan",
      "@psalm",
      "@test"
    ]
  }
}
     161
bash: line 8: cloc: command not found
0ef88ff0346a1a794052822fd00f81928ce4e1d5 2026-05-10 10:21:50 -0400
main
ls: infection*.json*: No such file or directory
ls: phpcs*.xml*: No such file or directory
ls: rector*.php*: No such file or directory
-rw-r--r--  1 nficano  staff  1231 May 10 09:26 .php-cs-fixer.dist.php
-rw-r--r--  1 nficano  staff  1265 May 10 09:46 phpstan.neon.dist
-rw-r--r--@ 1 nficano  staff  1214 May 10 09:27 phpunit.xml.dist
-rw-r--r--  1 nficano  staff  1444 May 10 10:19 psalm.xml
./vendor/myclabs/deep-copy/src/DeepCopy/Matcher/PropertyTypeMatcher.php
./vendor/myclabs/deep-copy/src/DeepCopy/DeepCopy.php
./vendor/myclabs/deep-copy/src/DeepCopy/Filter/ReplaceFilter.php
./vendor/myclabs/deep-copy/src/DeepCopy/Filter/Doctrine/DoctrineEmptyCollectionFilter.php
./vendor/myclabs/deep-copy/src/DeepCopy/Filter/Doctrine/DoctrineCollectionFilter.php
./vendor/myclabs/deep-copy/src/DeepCopy/Filter/SetNullFilter.php
./vendor/myclabs/deep-copy/src/DeepCopy/TypeFilter/Date/DatePeriodFilter.php
./vendor/clue/ndjson-react/src/Encoder.php
./vendor/clue/ndjson-react/src/Decoder.php
./vendor/autoload.php
./vendor/phpstan/phpstan/bootstrap.php
./vendor/nikic/php-parser/lib/PhpParser/Internal/TokenPolyfill.php
./vendor/nikic/php-parser/lib/PhpParser/PhpVersion.php
./vendor/friendsofphp/php-cs-fixer/src/Fixer/ClassNotation/StringableForToStringFixer.php
./vendor/friendsofphp/php-cs-fixer/src/Fixer/ClassNotation/FinalInternalClassFixer.php
./vendor/friendsofphp/php-cs-fixer/src/Fixer/ClassNotation/NoRedundantReadonlyPropertyFixer.php
./vendor/friendsofphp/php-cs-fixer/src/Fixer/ClassNotation/PhpdocReadonlyClassCommentToKeywordFixer.php
./vendor/friendsofphp/php-cs-fixer/src/Fixer/ControlStructure/TrailingCommaInMultilineFixer.php
./vendor/friendsofphp/php-cs-fixer/src/Fixer/FunctionNotation/NullableTypeDeclarationForDefaultNullValueFixer.php
./vendor/friendsofphp/php-cs-fixer/src/Fixer/LanguageConstruct/NullableTypeDeclarationFixer.php
>=8.4
not pinned
```

## Catalog

### 1. PHP version reality
- `composer.json require.php`: `>=8.4`.
- `config.platform.php`: not pinned.
- Running PHP: `8.4.21`.
- CI matrix lowest PHP version: not discoverable (`.github/workflows` directory is absent in this repo).
- First-party runtime version gates (`PHP_VERSION_ID` / `version_compare`): none found in `src/`, `tests/`, `bin/`, `samples/`.
- Note: the checklist `grep -r "PHP_VERSION_ID"` command includes `vendor/`, so its hits are all dependency files.

### 2. Framework / runtime
- Framework: none (library package, no Laravel/Symfony app skeleton).
- HTTP layer: no PSR-7 stack detected; transport is Amp/WebSocket + in-process transport.
- DI container: none detected (manual constructor injection).
- ORM/data layer: raw `PDO`/SQLite in `src/Store/EventLog.php` with prepared statements.
- Test framework: PHPUnit (`phpunit/phpunit ^11.0`).

### 3. Coding standard signals
- Config files present:
  - `.php-cs-fixer.dist.php`
  - `phpstan.neon.dist`
  - `phpunit.xml.dist`
  - `psalm.xml`
- Config files absent: `phpcs*.xml*`, `rector*.php*`, `infection*.json*`, `.editorconfig`.
- Tabs in first-party PHP files: 0 occurrences.
- Trailing whitespace in first-party PHP files: 0 occurrences.
- UTF-8 BOM at file start: none detected.
- Files missing `declare(strict_types=1);` (`src/`, `tests/`, `bin/`, `samples/`): 0.
- Files without namespace (`src/` + `tests/` PHP files): 0.
- `<?` short tags: none.
- `<?=` usage: none.
- Procedural-only files outside entry points (`src/` + `tests/`): none detected.

### 4. Type-system debt
- Methods with no return type (raw prompt regex): 2 matches.
  - Both are `__construct` methods (expected in PHP, no return type allowed).
  - Non-constructor methods missing return types: 0.
- Untyped properties (`public|private|protected $...`): 0.
- `mixed` usage in signatures/properties: 11.
  - Present in boundary/value-carrier APIs (notably `ToolHandler::invoke`, message payload DTOs, and integration-test handlers).
- PHPDoc typed `@param`/`@return` candidates (potential native-type duplication): 13 lines.
- PHPDoc `@param mixed` / `@return mixed` or missing-type markers: 0.
- `func_get_args()`, `...$args` untyped variadics: 0.

### 5. Forbidden-pattern audit
Counts are for `src/`, `tests/`, `bin/`, `samples/`.

- `@` suppression (`@\$|@[A-Za-z_]`, prompt pattern): 190.
  - Mostly PHPDoc tags and email strings (false positives for suppression intent).
- `@` suppression operator heuristic (`@` before variable/function expression): 0.
- `eval(`: 0.
- `extract(`: 0.
- `global` keyword: 1 (comment text, not executable statement).
- `goto`: 0.
- `die(`/`exit(` outside entry points: 0.
- `var_dump`/`print_r`/`dd(`/`dump(`: 0.
- `mt_rand`/`rand`/`array_rand`/`shuffle`/`str_shuffle`: 0.
- `md5`/`sha1`: 0.
- SQL interpolation candidates: 0.
- `@deprecated`: 0.
- `assert(` runtime validation: 0.
- Magic `__get` / `__set` / `__call` / `__callStatic`: 0.
- `static::` occurrences: 3 (`src/Ids/Id.php`).
- Additional security-relevant note:
  - `json_encode` without `JSON_THROW_ON_ERROR`: 9 (mostly tests, one in `src/Json/EnvelopeSerializer.php`).
  - `json_decode` without `JSON_THROW_ON_ERROR`: 2 (tests).

### 6. Architectural smells (inventory only)
- Files >500 lines: 1.
  - `src/Runtime/ARCPRuntime.php` (687 LOC).
- Classes with >20 public methods: 0.
- Methods >50 lines: 8.
  - Top: `ARCPRuntime::handleToolInvoke` (102), `ARCPRuntime::doHandshake` (82), `Capabilities::fromArray` (76), `ARCPClient::handle` (69).
- Cyclomatic complexity hotspots (estimated via AST pass):
  - `Capabilities::fromArray` (~22)
  - `Subscription::matches` (~16)
  - `MetricEvent::fromArray` (~16)
  - `ARCPRuntime::handleSubscribe` (~15)
  - `EnvelopeSerializer::envelopeToArray` (~15)
- Static method/global state signals:
  - `static function` count in `src/`: 165 (mostly DTO factory methods like `fromArray`).
  - `static $property` count: 0.
- Service Locator candidates (`->get(`): 3 occurrences, all domain/internal getter usage; no container service-locator pattern observed.

### 7. Existing CI / hooks / coverage integrations
- `.github/workflows/`: not present.
- Pre-commit tooling files (`captainhook.json`, `.husky/`, `grumphp.yml`): not present.
- `.git/hooks`: only default `.sample` hooks.
- Codecov/Coveralls config: not present.
- Existing local gate scripts are composer scripts (`lint`, `stan`, `psalm`, `test`, `gates`).

## Proposed phase order (adapted to current state)
1. Branch hygiene first: create `chore/modernize-php` from current HEAD, keep existing uncommitted work untouched.
2. Phase 1 tooling install/upgrade (add Rector, Infection, composer hygiene tools, architecture tests).
3. Phase 2 configs (`phpstan.neon.dist`, `.php-cs-fixer.dist.php`, `rector.php`, `infection.json5`, composer scripts, CI workflow).
4. Targeted Phase 4 type declarations: reduce `mixed` on first-party boundaries where feasible and document unavoidable `mixed`.
5. Targeted Phase 6 security/forbidden cleanup: enforce `JSON_THROW_ON_ERROR` policy and verify no suppression operator usage.
6. Phase 5 modern syntax pass via Rector sets (small reviewable batches).
7. Phase 7 architecture guardrails (Deptrac/Arkitect config + lint integration).
8. Verification pass: `composer ci`, Infection thresholds, PHPStan max with strict rules, Rector dry-run clean.
9. Produce `MODERNIZATION_RUNBOOK.md`.

## Approval gate
Discovery is complete. Awaiting explicit approval before proceeding to Phase 1+ edits.
