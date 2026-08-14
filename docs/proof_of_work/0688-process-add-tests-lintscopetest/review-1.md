# Review — Round 1 — issue #688

Branch: `process/issue-688-process-add-tests-lintscopetest-php-bin`
HEAD: `672a389` vs base `master`
Reviewer: review-critical agent (round 1)

## Earlier findings

Round 1 — `docs/proof_of_work/0688-process-add-tests-lintscopetest/findings-review.md`
did not exist before this round. No earlier findings to revisit.

## Overall verdict

**Sound change — no high or medium findings.** The diff does three things and
all three are correct and well-scoped:

1. Adds `bin/` to the lint scope of `phpstan.neon.dist`, `.php-cs-fixer.dist.php`
   and `rector.php` (closes #635).
2. Resolves the `tests/fixtures` vs `tests/Fixtures` case-collision by moving the
   single lowercase data file into the uppercase tree via `git mv` (100 % rename).
3. Adds `tests/LintScopeTest.php` with two guard tests, and refactors the three
   `bin/*.php` scripts so they are clean under PHPStan level 8 + php-cs-fixer +
   Rector (now that `bin/` is in scope).

I independently ran each lint component on `bin/` and the new test:

| check | command | result |
|---|---|---|
| PHPStan | `vendor/bin/phpstan analyse bin --level=8` | **OK — 0 errors** |
| php-cs-fixer | `vendor/bin/php-cs-fixer fix bin --dry-run --diff` | **0 of 4 files need fixing** |
| Rector | `vendor/bin/rector process bin --dry-run` | **OK — no changes** |
| kb-lint | `php bin/kb-lint.php` | **OK — 36 entries, 0 warnings** |
| LintScopeTest | `vendor/bin/phpunit --filter LintScopeTest` | **OK — 2 tests, 6 assertions** |

The "composer lint passed on bin/" claim is therefore **credible**.

## High-risk areas checked clean

### 1. bin/kb-lint.php `$currentBody` by-reference refactor (the key risk)

Reconstructed the exact parse flow and exercised it with a fixture containing
multiple `###` entries, a `####` sub-heading, and a fenced code block that
contains a line starting with `###`:

- A `###` heading appends a new entry, then re-binds
  `$currentBody = &$entries[count-1]['body']`. Body lines append through the ref.
- A `#`/`##` heading runs `unset($currentBody); $currentBody = null;` then
  `continue` — so body lines after a top/section heading are NOT collected
  (matches the old `$current !== null` gate).
- A `####` heading fails `preg_match('/^(#{1,3})\s+/')` and `continue`s **without**
  touching `$currentBody`, so body lines after a sub-heading stay with the parent
  `###` entry — identical to the old behaviour where `$current` was not reset.
- Fence toggle lines and fenced content are collected as body (the
  `$inFence || !str_starts_with('#')` branch), so a `###` inside a fence is
  treated as body, not a heading.

**The `unset($currentBody)` before `$currentBody = null;` is load-bearing.**
Without it, `$currentBody = null` would *write* `null` through the reference into
the entry's `body` array, destroying the already-collected lines. The
implementation includes the `unset`, so this is correct. I verified empirically:
the traced fixture produced 11 / 2 / 2 body lines for the three entries with no
loss and no misrouting.

`array_slice($body, 1)` is equivalent to the old `array_shift($body)` +
`array_values($body)`: both remove the first element and re-index to a clean
`list<string>`. When the first line is not a comment, the new code drops the
redundant `array_values`, which is safe because `$currentBody[] =` appends always
yield sequential `0..n` keys.

### 2. testNoCaseCollidingTrackedPaths()

- `strtolower($path)` on the full path is the correct granularity: only two
  *same path, different case* files collide (e.g. `tests/Fixtures/x` vs
  `tests/fixtures/x`). Different files in overlaying directories
  (`tests/Fixtures/a` vs `tests/fixtures/b`) get distinct lowercased keys and are
  correctly not flagged — they coexist harmlessly on a case-insensitive FS.
- `git ls-files` reads from the index (case-sensitive even on APFS), so the test
  catches a committed collision on macOS too, not only on Linux CI.
- `trim($stdout)` + `explode("\n")` + `array_filter(... !== '')` handles the
  trailing newline and the empty-repo edge case (`assertNotEmpty` guards the
  latter with a clear message).
- Verified on the current tree: **0 collisions** (`git ls-files | tr A-Z a-z |
  sort | uniq -d` is empty).
- The proc_open-failure path (`proc_open` returns `false`) would cascade PHP
  warnings before `assertIsResource` fails, but this is the **established repo
  pattern** — `tests/CheckCoverageGateTest.php` uses the identical
  `proc_open → assertIsResource → stream_get_contents → proc_close` sequence
  without a false-guard. Not a regression introduced here.

### 3. testBinInLintScope()

- `__DIR__ . '/bin'` assertions (cs-fixer, rector) are specific — `bin2`/`/bin`
  elsewhere cannot satisfy them.
- `- bin` (phpstan) is a substring match; see nit N-2 below.

### 4. Cross-cutting

- `grep -rn "/fixtures/" src tests bench benchmarks` → only the intentional
  historical mention in `tests/LintScopeTest.php:20` (a comment describing the
  now-resolved collision). No code reference to lowercase `fixtures` remains.
- `git ls-files tests/fixtures` → empty. `git ls-files tests/Fixtures | wc -l` →
  **14** (10 PHP + 3 XML + 1 TXT), matching the task expectation.
- `test_download.txt` is a 100 % rename; content preserved
  (`Test file download content`).
- Clover XML fixtures were already uppercase and untouched by the move; their
  references in `tests/CheckCoverageGateTest.php` use `/Fixtures/`.
- No BC break: all changes are dev/test/config; no published interface touched.
- No security concern: `escapeshellarg($this->projectDir)` on a path derived from
  `\dirname(__DIR__)`; no user input.
- Coverage floor unaffected: `@coversNothing` test that executes at 100 % adds
  covered lines to the aggregate; it cannot lower the 80 % floor.

## New findings

### N-1 | tests/LintScopeTest.php:20 | motivation comment mislabels the uppercase tree contents | nit

The class docblock says `tests/Fixtures/` held "13 PHP classes referenced by
PSR-4 autoload". In reality the pre-move uppercase tree had **13 files** = 10 PHP
+ 3 XML; of the PHP files only **3** (the `PollingMonitorWatcher/` set, namespace
`CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher`) are PSR-4
autoloaded classes — the other 7 are runner scripts included directly, and 3 are
XML data. The count 13 is right for the pre-move tree size, but "PHP classes" and
"PSR-4 autoload" overstate it. The motivating rationale (uppercase tree carries
autoloaded classes that must keep their case; lowercase tree carried one data
file) is still valid, so the fix direction is correct.

**Impact:** none on test behaviour; documentation imprecision only.
**Smallest safe fix:** correct the comment to "13 files (3 PSR-4 autoloaded
classes, 7 runner scripts, 3 XML)".
**Automated check that could catch it:** none — comments are outside every
linter's scope.

### N-2 | tests/LintScopeTest.php:51 | `- bin` substring assertion admits a `bin2` typo | nit

`assertStringContainsString('- bin', $phpstan)` treats `- bin` as a bare
substring. A future mis-edit to `- bin2` (or any `- binXYZ`) would still satisfy
the assertion (false negative) because `- bin2` *contains* `- bin`. The cs-fixer
and rector assertions use the precise `__DIR__ . '/bin'` form and are not
affected.

**Impact:** negligible — such a typo is implausible, and PHPStan itself errors on
a non-existent analysed path, so the guard's purpose (catch *removal* of `bin`
from scope) is still served.
**Smallest safe fix:** anchor the match, e.g. `assertMatchesRegularExpression(
'/^\s*-\s+bin\s*$/m', $phpstan)` to require a standalone list item.
**Automated check that could catch it:** none — string-contains assertions are
not type-checked.

## Candidate knowledge-base entries

### Candidate KB-1
**Title:** `unset($ref); $ref = null;` is required to break an array-element reference before nulling — a bare `$ref = null` writes through it
**Tags:** phpstan, lint, refactoring, references
**Trigger:** refactoring an `$entries[count-1][...][] = $line` indirect-append into a by-reference cursor, or any refactor that introduces `&$array[k]`
**Paragraph:** When a variable is bound by reference to an array element
(`$cursor = &$entries[k]['body']`), a later `$cursor = null` does *not* clear the
variable — it writes `null` through the reference into `$entries[k]['body']`,
destroying the data collected so far. To detach the cursor you must
`unset($cursor)` first (which breaks the reference) and only then assign. The
`bin/kb-lint.php` `parseFile()` refactor in #688 relies on this: every heading
runs `unset($currentBody); $currentBody = null;` before a `###` re-binds the
cursor to the new entry; omitting the `unset` would silently null each entry's
body as the parser advanced. PHPStan does not flag this (a reference write looks
like a normal assignment), so it is a review-only hazard.

## Remaining risk areas checked clean or not fully verified

- **kb-lint parse equivalence:** verified by a stand-alone reconstruction with a
  tricky multi-entry/`####`/fenced fixture — clean.
- **`array_slice` vs `array_shift`+`array_values`:** verified equivalent — clean.
- **bin/ lint credibility:** all four components run independently — clean.
- **case-collision invariant on the live tree:** 0 collisions — clean.
- **stray lowercase `fixtures` references:** grep clean (one intentional comment)
  — clean.
- **BC break / security / coverage floor:** analysed — clean.
- **Not run:** `composer test` (boots the Workerman daemon on 8888/9999 — out of
  scope and disruptive per instructions). The two LintScopeTest methods were run
  in isolation without the daemon and pass.
