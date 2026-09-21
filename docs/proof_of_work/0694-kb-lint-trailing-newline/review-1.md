# review-1 — #694 kb-lint `--fix` trailing newline + index separator

Diff reviewed: `git diff master...HEAD` on
`refactor/issue-694-kb-lint-php-fix-does-not-normalize-the-t`.

Files:
- `bin/kb-lint.php`
- `tests/KnowledgeBase/KbLintScriptTest.php`
- `docs/proof_of_work/0694-kb-lint-trailing-newline/` (proof of work)

## 0. Earlier findings

`docs/proof_of_work/0694-kb-lint-trailing-newline/findings-review.md` did not
exist before this round (first review). Nothing to adjudicate. It is created
now with this round's entries.

## 1. Helpers read (TAG INDEX)

Tags matching the diff (`bin`, `lint`, `tests`, `phpstan`, `php82`):
- **FAQ-031** (`bin/` is inside linter scope) — respected: PHPStan and
  php-cs-fixer both include `bin/`; both were run on the changed script.
- **FAQ-037** (`php82` minimum) — checked: the new code uses only
  `rtrim`/`file_get_contents`/`file_put_contents` and list spread; the tests
  use `str_ends_with`/`str_contains` (PHP 8.0+). No 8.3+ syntax. Clean.
- **FAQ-014** (PHPStan byte ranges) — not applicable.
- **DEC-008** (`composer lint` canonical; checks must be safe mid-cycle) —
  respected: `--fix` is not wired into `lint`, only `lint-fix`.
- **DEC-009** (single writer) — respected: no `docs/helpers/` write by this
  diff; proposals only (below).
- **DEC-007** (coverage floor single source) — untouched; `bin/` is excluded
  from `<source>`, so no coverage impact.

No documented decision is violated by this diff.

## 2. Checks run

| Check | Command | Result |
|---|---|---|
| Unit tests | `phpunit --no-coverage tests/KnowledgeBase/KbLintScriptTest.php` | OK — 23 tests, 251 assertions |
| PHPStan 8 | `phpstan analyse bin/kb-lint.php tests/KnowledgeBase/KbLintScriptTest.php` | No errors |
| php-cs-fixer | `fix --dry-run --diff --path-mode=intersection <2 files>` | 0 of 2 fixable |
| Rector | `rector process <2 files> --dry-run` | OK |
| Real tree | `php bin/kb-lint.php --fix` then `git status --porcelain` | exit 0, **no working-tree change** |

The `.github/workflows` sweep (FAQ-032) does not apply — no workflow file is
touched. The only other class referencing kb-lint is
`tests/KnowledgeBase/KnowledgeBaseTest.php`; it runs the script *without*
`--fix`, so it is unaffected (and stays green).

## 3. Answers to the specific review questions

### 3.1 `writeIndex()` create path — double/missing separator

Verified by replaying the fixtures against `master`'s script and the new one,
plus extra edge-case sandboxes:

- **no `##` heading + no trailing newline** (append at EOF): new code emits
  `Body text.\n\n## Tag index` — separator present (before the fix it was
  `Body text.\n## Tag index`, the issue's create-path bug).
- **no `##` heading + one trailing newline**: byte-identical to the no-newline
  case (one blank line).
- **first `##` heading directly after content** (no blank): prepends `''`,
  exactly one blank line.
- **first `##` heading after an existing blank**: no prepend, existing blank
  used, exactly one blank line.
- **file starts with `##`** (`$at === 0`): no prepend, no spurious leading
  blank before the file.
- **`##` heading present, no trailing newline**: section inserted mid-file, and
  the final `rtrim(...) . "\n"` supplies the missing file-terminating newline.
- **no missing separator** was found in any state. The only anomaly is
  **2+ trailing newlines** (see F-1): the extra blank lines before the insertion
  point survive and the created index ends up two blank lines below the body.

### 3.2 `normalizeTrailingNewline()`

- **Already-correct LF file**: `rtrim($c,"\n")."\n" === $c`, so no rewrite —
  confirmed by unchanged mtime across two `--fix` runs on a scratch copy of the
  real `faq.md`.
- **0 or ≥2 trailing LFs**: collapsed to exactly one. The real reproduction
  (strip the trailing `\n` from `faq.md`, run `--fix`) now ends `0x0a`, and a
  second `--fix` is a no-op.
- **Empty file**: turns `''` into `"\n"`. Unreachable in practice (a missing
  index means `writeIndex()` already wrote a full section first), and harmless.
- **CRLF**: a file ending `\r\n` is left untouched (correct); a file ending
  `\r\n\r\n` is *not* collapsed (see F-5).
- **Does not mask an out-of-sync index**: it runs *after* the index check, does
  not touch `$errors`, and the exit code is still driven by `$errors`. A
  newline-only fix on a broken file still exits 1.
- **Does not interfere with `--fix` warnings**: the existing
  "tag index created/regenerated" warnings are unchanged; a newline-only
  normalization emits no warning by design (F-2/F-3).

### 3.3 Is running `--fix` on the real tree safe?

Yes. `docs/helpers/faq.md` and `docs/helpers/decisions.md` both already end in
exactly one `\n`, so `writeIndex()` is not reached and
`normalizeTrailingNewline()` detects no change. `php bin/kb-lint.php --fix`
exits 0 with only the two pre-existing budget warnings, and
`git status --porcelain` is empty afterwards. Verified in this round.

### 3.4 Tests

All three new tests genuinely exercise the branch they claim and **fail against
`master`'s `bin/kb-lint.php`** (replayed with the exact test fixtures):

| Test | Branch | Old script | New script |
|---|---|---|---|
| `testFixNormalizesAStrippedTrailingNewline` | regenerate (corrupted row) | ends without `\n` → FAIL | ends with one `\n` → PASS |
| `testFixNormalizesAStrippedTrailingNewlineEvenWhenTheIndexIsInSync` | in-sync + normalize | no rewrite → `assertSame` FAIL | original bytes restored → PASS |
| `testFixCreatesTheIndexWithABlankSeparatorRegardlessOfTrailingNewline` | create/append (no `##`) | no `\n\n## Tag index` → FAIL | present, 0- and 1-newline inputs equal → PASS |

The append test correctly removes the `## Section` heading too (otherwise the
insertion happens before the heading where the separator was never broken);
the `assertStringContainsString('### ')` / `assertStringNotContainsString('## Tag index')`
guards make that explicit. Naming and `self::` assertion style match the file;
one small style inconsistency is noted as F-4.

### 3.5 PHPStan 8 / PSR-12

Clean (table above). No type errors, no fixer diff, no Rector suggestions.

## 4. Findings

### F-1 — create-index append keeps a double blank line with 2+ trailing newlines — low

`bin/kb-lint.php:559-569`. With `$firstSection === null`, `$at = count($lines)`
includes every trailing empty element of `readLines()`. For an input ending in
two newlines the guard sees `$lines[$at-1] === ''` and does not prepend, so the
pre-existing blank line is kept *and* the section is appended after it:
`Body text.\n\n\n## Tag index` (verified). One trailing newline yields one blank
line, two yields two — so the output still depends on the trailing state, and
the test title "RegardlessOfTrailingNewline" / the code-decision note ("the
final `rtrim(..., "\n") . "\n"` collapses them to exactly one anyway") are both
inaccurate: `rtrim` only strips the *file tail*, not the now-internal blank
run. **Pre-existing** (the old code produced the same two blank lines); the new
code neither introduces nor fixes it. Cosmetic only — the result still lints
clean and is idempotent. No automated check catches it (the new test only
compares 0 vs 1 trailing newlines).

### F-2 — `normalizeTrailingNewline()` return value is never used — nit

`bin/kb-lint.php:585-602`, called at `:770`. The function is documented to
"return true when the file was rewritten", but the only caller ignores the
result. Either drop the return type to `void` or use it to report a
newline-only rewrite (see F-3). No automated check flags this.

### F-3 — `--fix` help text no longer describes what `--fix` does — nit

`bin/kb-lint.php:17` (file docblock) and `:610` (`printUsage`): "`--fix`
regenerate the tag index of every knowledge-base file". Since this diff,
`--fix` also normalises the trailing newline of a file whose index is already
in sync (and emits no warning for that), so the documented contract is
incomplete. Update both strings (and `bin/README.md` if it repeats the line).
No automated check.

### F-4 — new tests mix assertion styles — nit

`tests/KnowledgeBase/KbLintScriptTest.php:217-218` and `:261` use
`str_ends_with(...)` with `assertTrue`/`assertFalse`, while the same file
already uses PHPUnit's `assertStringEndsNotWith` at `:210`. Prefer
`assertStringEndsWith("\n", $content)` / `assertStringEndsNotWith("\n\n", $content)`
for consistency. php-cs-fixer does not enforce this.

### F-5 — CRLF files with multiple trailing line endings are not normalised — nit

`bin/kb-lint.php:593`. `rtrim($contents, "\n")` cannot remove the `\r` before a
second `\n`, so a file ending `\r\n\r\n` is left as-is (verified), while the
docblock claims "rewrites a file that does not end in exactly one newline". LF
is the repo convention and `writeIndex()` already converts CRLF→LF whenever it
rewrites, so impact is low; if CRLF normalisation is intended, the rule needs
explicit `\r\n` handling. No automated check.

### F-6 — existing `## Tag index` heading without markers is duplicated by `--fix` — low (pre-existing)

`bin/kb-lint.php:558-569`. If a file keeps its `## Tag index` heading but the
`<!-- kb-index:start/end -->` markers are removed, `parseFile()` reports no
index and `$firstSection` points at that heading; the create path splices a new
`## Tag index` section *before* it, producing two identical headings (verified:
`## Tag index … <!-- kb-index:end -->\n\n## Tag index`). The subsequent lint
passes because only the markers matter, so the corruption is silent. Not
introduced by this diff, but it is the direct neighbour of the code this PR
touches and the new test deliberately edits that heading. No test covers it.

## 5. Candidate helper entries (proposal only — do NOT append)

The coder's candidates A and B are valid and worth landing. One addition:

### Candidate C — title: `rtrim(..., "\n")` normalises only the file tail, not internal blank runs (and not CRLF)

- **Tags:** `bin`, `lint`, `tests`, `php-strings`
- **Trigger:** normalising line endings or blank separators in a generated
  Markdown/PHP-text file.
- **Paragraph:** `rtrim($s, "\n") . "\n"` guarantees exactly one trailing LF
  only when the blank run is at the *end* of the string. Once content is
  appended after an existing trailing blank run (e.g. `bin/kb-lint.php`'s
  create-index path inserting `## Tag index` at EOF), those blank lines become
  internal and survive, so the result still depends on how many trailing
  newlines the input had. `rtrim` also cannot collapse CRLF runs, because the
  `\r` blocks it. When the output must be byte-identical for all trailing
  states, strip the trailing blank *lines* before computing the insertion point
  (or normalise `\r\n` first). Verified in #694 (review round 1, F-1/F-5).

## 6. Verdict

No high/medium finding. The change fixes the issue's reproduction (both the
regenerate and the in-sync path), the create-path separator is correct for the
documented states, the tests genuinely fail before the fix, the real tree is
untouched, and all gates (PHPStan 8, php-cs-fixer, Rector, PHPUnit) are green.
Open findings: F-1 (low), F-6 (low, pre-existing), F-2/F-3/F-4/F-5 (nit).
