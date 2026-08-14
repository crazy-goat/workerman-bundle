# Code Decision 1 — issue #688: bin/ lint scope, tests/Fixtures case collision, LintScopeTest

## Step A — tests/Fixtures case collision fix

**Decision:** uppercase `tests/Fixtures/` is the canonical directory. The single
lowercase file `tests/fixtures/test_download.txt` was moved into the uppercase
tree via `git mv tests/fixtures/test_download.txt tests/Fixtures/test_download.txt`.

**Rationale:** the uppercase tree (13 files) contains PHP classes referenced by
PSR-4/autoload (e.g. `PollingMonitorWatcher/CountingPollingMonitorWatcher.php`).
PHP class file paths must match their namespace case under PSR-4 on
case-sensitive filesystems. The lowercase tree held only one static data file
(`test_download.txt`) with no autoload implications. Moving the data file into
the uppercase tree breaks the least and preserves git history.

**References updated (case-sensitively):**
- `tests/App/ResponseTestController.php:34` — `'../fixtures/` → `'../Fixtures/`
- `tests/App/ResponseTestController.php:65` — `'../fixtures/` → `'../Fixtures/`
- `tests/ResponseTest.php:87` — `'/fixtures/` → `'/Fixtures/`
- `tests/MarkdownLinkTest.php:20` — comment updated to past tense (collision resolved)

**Rejected:** renaming the uppercase tree to lowercase. Would break PSR-4 autoload
of 13 PHP classes and require renaming namespaces/directories — far more invasive
for no benefit.

**Result:** `git ls-files tests/Fixtures | wc -l` = 14, `git ls-files tests/fixtures | wc -l` = 0.

## Step B — bin/ added to lint scope

Added `bin` to the `paths:` list of all three tools, consistent with how
src/tests/benchmarks are declared:
- `phpstan.neon.dist` → `paths:` now includes `- bin`
- `.php-cs-fixer.dist.php` → Finder `->in(__DIR__ . '/bin')`
- `rector.php` → `withPaths([...])` now includes `__DIR__ . '/bin'`

## Step C — PHPStan/php-cs-fixer/Rector fixes in bin/

14 PHPStan errors surfaced across 3 files. All fixed with real fixes, no
ignoreErrors:

### bin/check-coverage.php (4 errors)
- **L12/L17 `$argc`/`$argv` undefined:** PHPStan does not recognize the global
  CLI variables `$argc`/`$argv`. Fixed by reading `$_SERVER['argc']` and
  `$_SERVER['argv']` into locals — the standard PHPStan-compatible pattern.
- **L36 offset 0 might not exist on xpath result:** `SimpleXMLElement::xpath()`
  returns `array<SimpleXMLElement>|false|null`. Changed the guard from
  `!== false && !== []` to `is_array() && !== []`, then access `[0]` safely.
- **L48 foreach over non-iterable:** same xpath result type. Changed
  `=== false || === []` to `!is_array() || === []` for the fallback path.

### bin/kb-lint.php (6 errors)
- **L296 offset access on `$entries[count-1]['body']`:** PHPStan could not
  prove the computed offset exists in the list. Replaced the indirect
  `$entries[\count($entries) - 1]['body'][] = $line` pattern with a
  `$currentBody` reference variable (`&$entries[...]['body']`) that is set
  when a new entry is appended and unset when the current section ends.
  This is both cleaner (no repeated count/offset arithmetic) and type-safe.
- **L345 offset 0 / L346 array_shift / L348 array_values:** the body-trimming
  foreach used `array_shift($body)` + `array_values($body)`. PHPStan inferred
  `$body` as a non-list array after shift and flagged `array_values` as a
  no-op on a list. Replaced with `$body = array_slice($body, 1)` which returns
  a clean list and needs no `array_values` reindex.
- **L358 return type mismatch:** the `@var` annotation on `$entries` plus the
  reference-variable refactor resolved the inferred `mixed` values into the
  declared shape.
- **L829 `$argv` undefined:** replaced `$argv` with `$_SERVER['argv'] ?? []`.

### bin/pick-issue.php (4 errors)
- **L196/L358 missing iterable value type:** added `@return list<array<string, mixed>>`
  to `sortMilestones()` and `@return array{repo: string, milestone: string|null,
  top: int, json: bool}` to `parseArgs()`.
- **L462 non-list argument to sortMilestones:** `ghApi()` returns `mixed`;
  after `is_array()` guard the result is `non-empty-array<mixed>`, not a list.
  Added `array_values()` to normalize to a list before passing to `sortMilestones`.
- **L625 `$argv` undefined:** replaced `$argv` with `$_SERVER['argv'] ?? []`.

### php-cs-fixer (1 issue)
- `bin/check-coverage.php`: missing trailing comma in multi-line `printf` args.

### Rector (4 rules in pick-issue.php)
- `ClosureToArrowFunctionRector`: `usort` closure → arrow function
- `FunctionFirstClassCallableRector`: `'escapeshellarg'` → `escapeshellarg(...)`
- `ForRepeatedCountToOwnVariableRector`: `count($argv)` hoisted to `$counter`
- `RemoveConcatAutocastRector`: removed redundant `(string)` cast on `$issue['rationale']`

## Step D — tests/LintScopeTest.php

Two tests, following the style of `tests/BinDirectoryTest.php` (repo root via
`\dirname(__DIR__)`, file content assertions) and `tests/CheckCoverageGateTest.php`
(proc_open for subprocess invocation):

1. `testBinInLintScope()` — reads the three config files and asserts `bin`/`bin/`
   appears in each.
2. `testNoCaseCollidingTrackedPaths()` — runs `git ls-files` via proc_open, builds
   a map keyed on `strtolower($path)`, flags any key with >1 distinct originals.
   This exactly captures "two tracked paths that differ only in case" — the class
   of defect that overlays on macOS but breaks on Linux CI.

## Step E — validation

- `composer lint` — all 4 checks pass (php-cs-fixer, phpstan, rector, kb-lint)
- `composer test` — 2024 tests, 0 failures, 31 skipped
- `tests/LintScopeTest.php` — 2 tests, 6 assertions, passes locally

## Unsure about

- The `testNoCaseCollidingTrackedPaths` assertion is strict: it flags ANY
  case-colliding pair, not just `tests/Fixtures` vs `tests/fixtures`. This is
  intentional (the whole repo should be collision-free) but means a future
  file added with a case variant of an existing path would fail this test.
  That is the desired behavior.
