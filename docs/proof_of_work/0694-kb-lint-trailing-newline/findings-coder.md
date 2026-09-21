# Findings — coder (#694)

## Obstacles

- **Reaching the create-index append path in the test fixtures.** The issue's
  separator bug only manifests when the tag index is inserted at end-of-file,
  i.e. when `parseFile()` finds no `##` heading (`$firstSection === null`,
  `writeIndex()` line 559). The existing `file()` helper always emits a
  `## Section` heading, and the existing `testFixCreatesAMissingTagIndex`
  removes only the index block, so the index is inserted *before* `## Section`
  — where the preceding line is already blank and the trailing-newline state
  does not matter. The new test therefore also strips the `## Section` heading
  (regex `...\n\n## Section\n`), which is the minimal change that exercises the
  append branch. A test that only removed the index would have passed both
  before and after the fix and proved nothing.
- **Making `--fix` actually write on a fixture with a missing trailing
  newline.** `writeIndex()` is only called when the index is missing or out of
  sync (`main()` lines ~710–728). Stripping the trailing newline alone is not
  enough — the first test also corrupts the index row (`- \`wrong\` — FAQ-999`)
  so the regenerate branch runs. Worth remembering for any future regression
  test of the write path.

No build/tooling obstacles: `phpstan` (level 8) and `php-cs-fixer` were clean
on both files, and `rector --dry-run` reported no changes. The real
`docs/helpers/` files were never modified (`php bin/kb-lint.php --fix` left
`git diff` limited to the two intended files).

## Bugs / weak spots discovered

### 1. `--fix` does not normalise a file whose index is already in sync — in scope-adjacent, not fixed

`bin/kb-lint.php:710-728` only calls `writeIndex()` when `$parsed['index']`
is `null` or its body differs from `renderIndex()`. A KB file with an in-sync
index but a stripped trailing newline still lints clean (`code 0`) and
`--fix` leaves it byte-for-byte unchanged, so the new normalisation never runs.

Reproduced: fixture with `- \`alpha\` — FAQ-001` and body ending without
`"\n"` → `php bin/kb-lint.php --root=$S` exits 0; `php bin/kb-lint.php --fix
--root=$S` exits 0 and `tail -c1` is still `0x2e` (`.`), not `0x0a`.

Suggested fix (if a follow-up wants full normalisation): in the `--fix` loop,
when `$options['fix']` and the raw file does not end in exactly one `"\n"`,
call `writeIndex(...)` even for an in-sync index (or detect the trailing state
directly and rewrite). Keep it out of #694 to match the issue's stated scope
of `writeIndex()`. This is the only place where "`--fix` normalises the
trailing newline" is not yet universally true.

### 2. Existing kb-lint line-budget warnings on the real KB — pre-existing

`php bin/kb-lint.php` on the workspace reports `docs/helpers/faq.md` at 409
budgeted lines and `docs/helpers/decisions.md` at 310, against a
`LINE_BUDGET` of 300 (`bin/kb-lint.php:68`). Warnings only; not caused by this
change and not touched.

### 3. Test helper duplicates the index-render logic — pre-existing

`tests/KnowledgeBase/KbLintScriptTest.php:408-454` (`file()`) re-implements
`renderIndex()` (`bin/kb-lint.php:513-540`) by hand. If the index format ever
changes, the fixtures silently fall out of sync and tests may pass against an
index shape the production code no longer emits. Not a defect today, and the
duplication is what lets fixtures be built without invoking the script; no fix
proposed, just a maintenance risk.

## Candidate helper entries (proposal only — do NOT append)

### Candidate A — title: `--fix` only rewrites when the index changed

- **Tags:** `bin`, `lint`, `tests`
- **Trigger:** adding or reviewing a `--fix` normalisation in `bin/kb-lint.php`.
- **Paragraph:** `bin/kb-lint.php` calls `writeIndex()` only when a KB file's
  tag index is missing or out of sync (`main()` lines ~710–728). Normalising
  anything on the write path therefore does not affect files that already have
  an up-to-date index. Pin the branch a test intends to exercise: strip the
  index or corrupt it *and* change the trailing state, otherwise the fixture
  passes before and after the change. Verified in #694.

### Candidate B — title: create-index insertion must own its blank separator

- **Tags:** `bin`, `lint`, `tests`
- **Trigger:** touching the tag-index creation path in `bin/kb-lint.php`.
- **Paragraph:** the create-index path can insert at end-of-file when a file
  has no `##` heading. Relying on the input's trailing newline to separate the
  last body line from `## Tag index` makes the output depend on an unrelated
  input detail; supply the blank line explicitly when the preceding line is
  not already blank, and normalise the written bytes to exactly one trailing
  newline. Regression fixtures must cover the append branch (no `##` heading)
  and compare the stripped- and kept-newline inputs byte-for-byte. Verified in
  #694.

## Checks run

- `php -d phar.readonly=0 vendor/bin/phpunit --no-coverage tests/KnowledgeBase/KbLintScriptTest.php`
  → 22 tests, 235 assertions, OK.
- `vendor/bin/phpstan analyse bin/kb-lint.php tests/KnowledgeBase/KbLintScriptTest.php --no-progress`
  → No errors (level 8).
- `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --path-mode=intersection bin/kb-lint.php tests/KnowledgeBase/KbLintScriptTest.php`
  → 0 of 2 files fixable.
- `vendor/bin/rector process bin/kb-lint.php tests/KnowledgeBase/KbLintScriptTest.php --dry-run` → OK.
- `php bin/kb-lint.php` and `php bin/kb-lint.php --fix` on the real workspace
  → exit 0; only the two pre-existing budget warnings; `git diff` shows no
  `docs/helpers/` change.
