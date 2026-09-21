# review-2 — #694 kb-lint `--fix` trailing newline + index separator

Diff reviewed: `git diff master...HEAD` on
`refactor/issue-694-kb-lint-php-fix-does-not-normalize-the-t` (HEAD `7dc27a0`,
round-2 commit "fix(bin): address review round 1 for #694").

Files:
- `bin/kb-lint.php`
- `tests/KnowledgeBase/KbLintScriptTest.php`
- `docs/proof_of_work/0694-kb-lint-trailing-newline/` (proof of work)

## 0. Earlier findings — adjudication

`findings-review.md` was read first. Round 1 recorded F-1..F-6. For each:

| # | Round-1 claim | Status in round 2 | Evidence |
|---|---|---|---|
| F-1 | create-index **append** path (`$firstSection === null`) kept the whole trailing blank run, so 2+ trailing newlines yielded two blanks before the created heading | **fixed** (for the branch it named) | `bin/kb-lint.php:568-570` collapses the run and `:574-576` re-adds one blank. Replayed against a scratch sandbox: `Body.` + 0/1/2/3 trailing `\n` all produce the byte-identical `Body.\n\n## Tag index …`. `testFixCollapsesExtraTrailingNewlinesBeforeACreatedIndex` (`:273-295`) fails against master's and round-1's script and passes now. **However** the same collapse is broken on the sibling `$firstSection !== null` branch — see new F-7. |
| F-2 | `normalizeTrailingNewline()` documented a bool nobody read | **fixed** | `bin/kb-lint.php:595` is `function normalizeTrailingNewline(string $absolute): void`; docblock (`:587-594`) describes write-only-when-changed, no return. Caller `:780-782` discards nothing. |
| F-3 | `--fix` help/file docblock stale | **fixed** | File docblock `bin/kb-lint.php:17-18`, `printUsage()` `:620-621`, `bin/README.md:168` all now mention the trailing-newline normalisation. `grep -rn "regenerate the tag index" bin/` shows only the updated two strings. |
| F-4 | new tests mixed `str_ends_with`+`assertTrue` with the file's `assertStringEnds*` style | **fixed** | `grep -n "str_ends_with\|assertStringEnds" tests/KnowledgeBase/KbLintScriptTest.php` returns only `assertStringEndsWith`/`assertStringEndsNotWith` (lines 210, 217, 218, 228, 253, 261, 293, 294). |
| F-5 | `rtrim($contents, "\n")` cannot collapse a `\r\n\r\n` tail | **fixed** | `bin/kb-lint.php:605` is `rtrim($contents, "\r\n") . "\n"`. A CRLF fixture now ends `0x0a` (verified: tail hex `656e64202d2d3e0a`). Side effect recorded as new F-8. |
| F-6 | `## Tag index` heading present but markers missing → create path splices a second heading | **still present — deliberately not fixed** | Reproduced: input `…Body.\n\n## Tag index\n\nSome stale text.\n` → output contains the new index block followed by `\n\n## Tag index\n\nSome stale text.` and lints clean (exit 0). Acceptable to defer **only** if a separate issue is actually filed with this reproduction (see §5). |

Round-1 notes on automated checks remain accurate: none of F-1..F-6 is caught by
PHPStan / php-cs-fixer / Rector; F-1-class defects are caught only by the
create-path tests, which do not cover the section branch (F-7).

## 1. Helpers read (TAG INDEX)

Tags matching the diff (`bin`, `lint`, `tests`, `phpstan`, `php82`,
`knowledge-base`):

- **FAQ-031** (`bin/` is inside linter scope) — respected: PHPStan and
  php-cs-fixer both cover `bin/`; both were run on the changed files (green).
- **FAQ-037** (`php82` minimum) — checked: the new code uses only
  `trim`/`rtrim`/`file_get_contents`/`file_put_contents`/list spread; the tests
  use `assertStringEndsWith`. No 8.3+ syntax. Clean.
- **FAQ-014** (PHPStan byte ranges) — not applicable.
- **DEC-008** (`composer lint` canonical; checks safe mid-cycle) — respected:
  `--fix` stays wired only to `composer lint-fix` (`composer.json:103-107`),
  never to `lint`. No new repository-wide check.
- **DEC-009** (single writer) — respected: no `docs/helpers/` write by this
  diff; proposals only (§6).
- **DEC-007** (coverage floor single source) — untouched; `bin/` is outside
  `<source>`, so no coverage impact.
- **DEC-021** (changelog) — not triggered: no class/method removal or
  thrown-type change.

No documented decision is violated by this diff.

## 2. Checks run

| Check | Command | Result |
|---|---|---|
| Unit tests | `phpunit --no-coverage tests/KnowledgeBase/KbLintScriptTest.php` | OK — 24 tests, 265 assertions |
| KB sweep | `phpunit --no-coverage tests/KnowledgeBase/` | OK — 41 tests, 1152 assertions |
| PHPStan 8 | `phpstan analyse bin/kb-lint.php tests/KnowledgeBase/KbLintScriptTest.php` | No errors |
| php-cs-fixer | `fix --dry-run --diff --config=.php-cs-fixer.dist.php --path-mode=intersection <2 files>` | 0 of 2 fixable |
| Rector | `rector process <2 files> --dry-run` | OK |
| Real tree | `php bin/kb-lint.php --fix` then `git status --porcelain` | exit 0, **no working-tree change** |

The `.github/workflows` sweep (FAQ-032) does not apply — no workflow file is
touched. `grep -rln "kb-lint" tests/` returns only
`tests/KnowledgeBase/KnowledgeBaseTest.php` (runs the script without `--fix`)
and `KbLintScriptTest.php`; both green.

I also replayed the create path against a scratch sandbox with three script
versions (master, round-1 `b1b5d39`, HEAD) for every input state; the matrix is
in §3.

## 3. F-1 re-check: create path against every input state

Replayed `--fix` on a sandbox with a valid entry and a missing index, varying
only the tail/heading. Result bytes between the last body line and the first
`##` section:

| Input state | master | round-1 | HEAD |
|---|---|---|---|
| no `##` heading, 0 trailing `\n` | `Body.\n## Tag index` | `Body.\n\n## Tag index` | `Body.\n\n## Tag index` |
| no `##` heading, 1/2/3 trailing `\n` | `Body.\n\n\n## Tag index` (2 blanks) | same | `Body.\n\n## Tag index` |
| `## Section` with **no** blank before it | `Body.\n## Tag index …\n\n## Section` | `Body.\n\n## Tag index …\n\n## Section` | `Body.\n\n## Tag index …\n\n## Section` |
| `## Section` with **one** blank before it | `Body.\n\n## Tag index …\n\n## Section` | `Body.\n\n## Tag index …\n\n## Section` | `Body.\n\n## Tag index …\n\n\n## Section` ← **regression** |
| `## Section` with **two** blanks before it | `Body.\n\n\n## Tag index …\n\n## Section` | same | `Body.\n\n## Tag index …\n\n\n\n## Section` |
| file starts with blanks, then `## Section` | leading blanks kept | leading blanks kept | leading blanks kept **after** the index (3 blanks) |
| `##` heading at line 1 (`$at === 0`) | `## Tag index …\n\n## Section` | same | same (correct) |
| empty file / only blanks | index at top | index at top | index at top (correct) |

The append branch F-1 named is genuinely fixed. The section branch is not: the
collapse decrements `$at`, but the tail is sliced from the *collapsed* `$at`
(`bin/kb-lint.php:578`), so the blank run it just "removed" is still in the
tail and the unconditional `''` prepend (`:574-576`) adds a second blank. On the
append branch this is invisible because the leaked blanks are at EOF and the
final `rtrim` (`:584`) eats them; on the section branch they are internal and
survive. New finding F-7.

## 4. F-2..F-5 re-check

- **F-2** — `void`, no ignored return. Fixed.
- **F-3** — docblock + usage + `bin/README.md` consistent. Fixed.
- **F-4** — only `assertStringEnds*` in the new tests. Fixed.
- **F-5** — `rtrim($contents, "\r\n") . "\n"`. A file ending `\r\n\r\n` now
  collapses to one LF. Fixed; the mixed-ending side effect is F-8.

## 5. F-6 judgment

F-6 (heading without markers → duplicate `## Tag index`) is still present and
was deliberately not fixed. That is **acceptable for this PR**: it is a distinct
pre-existing defect that needs marker-vs-heading reconciliation (or a new
"index heading without markers" lint error) and its own tests, and #694 is about
trailing-newline normalisation. The condition is that the separate issue is
actually opened with the reproduction above; until then it is an open finding on
the record, not a closed one. Note the round-2 change does not make F-6 worse
(same duplicate heading, now with F-7's extra blank after the first block).

## 6. New findings

### F-7 — create path leaks the collapsed blank run on the `$firstSection !== null` branch — medium

`bin/kb-lint.php:568-578`. The `while` loop moves `$at` back over the blank run
before the first `##` heading, but the insertion still uses that collapsed `$at`
for the tail slice (`:578`), so the blanks stay in the tail; the unconditional
`if ($at > 0) { $section = ['', …] }` (`:574-576`) then adds a second blank.
Result: any file whose first `##` heading is preceded by a blank line (the
normal Markdown layout, and the layout of a brand-new KB file with sections)
gets **two** blank lines between the created index and that heading. This is a
regression introduced by round 2: master and round-1 produced exactly one blank
for that input (verified, §3). It contradicts the code-decision-2 claim that the
collapse "makes 'exactly one' true for every input state", and the new
`testFixCollapsesExtraTrailingNewlinesBeforeACreatedIndex` misses it because it
removes `## Section`, forcing the append branch. Cosmetic (still lints clean,
still idempotent), but it is a regression in the exact function under review.

Suggested fix — slice the tail from the *original* insertion point:

```php
$insertAt = $firstSection !== null ? $firstSection - 1 : \count($lines);
$at = $insertAt;
while ($at > 0 && trim($lines[$at - 1] ?? '') === '') {
    --$at;
}
$section = ['## Tag index', '', INDEX_START, ...$rendered, INDEX_END, ''];
if ($at > 0) {
    $section = ['', ...$section];
}
$lines = [...\array_slice($lines, 0, $at), ...$section, ...\array_slice($lines, $insertAt)];
```

Check that would catch it: a create-path test that keeps a `## Section` preceded
by one blank and asserts `"kb-index:end -->\n\n## Section"` (and
`"\n\n## Tag index"`). Because F-1 is the same class of defect, that assertion
belongs **in this PR**, extending
`testFixCreatesTheIndexWithABlankSeparatorRegardlessOfTrailingNewline` /
`testFixCollapsesExtraTrailingNewlinesBeforeACreatedIndex` to the section branch,
rather than being filed as a new issue.

### F-8 — `normalizeTrailingNewline()` turns the final `\r\n` of an in-sync CRLF file into a bare LF, producing mixed line endings — nit

`bin/kb-lint.php:603-611`. `rtrim($contents, "\r\n") . "\n"` rewrites only the
tail, so a CRLF file whose index is already in sync ends up with every line
`\r\n` except the last (`0x0a`). Verified: fixture with 11 CRLF pairs and an
in-sync index is left with 11 `\r\n` + 1 bare `\n`. Round-1/master left such a
file untouched (fully CRLF); `writeIndex()` converts the whole file to LF, so
the two paths now disagree. Impact is low (the repo is LF-only, so a CRLF file
is non-conforming anyway), but "leave CRLF alone" and "normalise the whole file"
are both more coherent than "mixed". No automated check covers it (there is
still no CRLF fixture). If the intended contract is "the file must end in
exactly one LF", document the mixed-ending outcome in the docblock; otherwise
restrict the rewrite to LF-only files.

## 7. Other checks (no finding)

- PSR-4 / Bundle conventions: `bin/kb-lint.php` is a script, `tests/…` follows
  the existing namespace and `final` test-class style.
- Type correctness: PHPStan 8 clean; `void` return, `?int $firstSection`,
  `?array $index` unchanged.
- Error handling: `file_get_contents` false is handled in
  `normalizeTrailingNewline`; missing files are rejected before `writeIndex`.
- Security: no HTTP input; the script reads/writes only paths derived from
  `--root`/`KB_LINT_ROOT`; no new subprocess or unserialize surface.
- Coverage: `bin/` is excluded from `<source>` (FAQ-031), so the floor is
  untouched — not lowered.

## 8. Candidate helper entries (proposal only — do NOT append)

Round 1's Candidate C is still valid. One addition:

### Candidate D — a blank-run collapse must slice the tail from the original insertion index

- **Tags:** `bin`, `lint`, `php-strings`, `tests`
- **Trigger:** removing/normalising a run of blank lines immediately before an
  insertion point in a text file.
- **Paragraph:** When you move an insertion index `$at` backwards over a blank
  run and then rebuild the string as `head(0, $at) + section + tail($at)`, the
  collapsed blank lines are still inside `tail($at)` — the collapse only changed
  where the head is cut. If `section` also ends (or starts) with a blank, the
  run reappears, doubled. On an append-at-EOF path this is invisible because the
  trailing `rtrim` removes it; before an internal heading it is not. Keep the
  original insertion point for the tail slice: `head(0, $at) + section +
  tail($insertAt)`. Caught by a test that exercises the internal-heading branch,
  not just EOF. Verified in #694 review round 2 (F-7): master/round-1 emitted one
  blank before the existing `##` heading, round 2 emitted two.

## 9. Verdict

**Not clean.** F-1 (append path) is genuinely fixed, F-2/F-3/F-4/F-5 are
genuinely fixed, and all gates are green, but the round-2 collapse fix
introduced a regression on the sibling create-path branch (F-7, `medium`), which
the new tests do not cover. F-6's deferral is acceptable provided a separate
issue is filed. F-8 is a `nit`.

Open findings: F-7 (medium, regression), F-8 (nit), F-6 (low, pre-existing,
deferred), plus the round-1 nits that are now fixed.
