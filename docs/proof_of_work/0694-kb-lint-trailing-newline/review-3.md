# review-3 — #694 kb-lint `--fix` trailing newline + index separator (round 3, final)

Diff reviewed: `git diff master...HEAD` on
`refactor/issue-694-kb-lint-php-fix-does-not-normalize-the-t` (HEAD `ba46f16`,
"fix(bin): address review round 2 for #694"). Round-3 delta is `7dc27a0..HEAD`.

Files:
- `bin/kb-lint.php`
- `tests/KnowledgeBase/KbLintScriptTest.php`
- `bin/README.md`
- `docs/proof_of_work/0694-kb-lint-trailing-newline/` (proof of work)

## 0. Earlier findings — adjudication

`findings-review.md` was read before anything else. Open findings were F-6, F-7,
F-8 (F-1..F-5 were closed in round 2). For each:

| # | Claim | Status in round 3 | Evidence |
|---|---|---|---|
| F-6 | `## Tag index` heading present but markers missing → create path splices a second heading that still lints clean | **still present — deliberately deferred** | Reproduced at HEAD: input `Body.\n\n## Tag index\n\nSome stale text.\n` → output contains the new index block followed by a second `## Tag index\n\nSome stale text.`; `php bin/kb-lint.php` exits 0. Unchanged by round 3. Deferral acceptable only if step 14 opens the issue with this reproduction (see §5). |
| F-7 | round-2 collapse moved `$at` and sliced the tail from the collapsed index → doubled blank before a blank-preceded existing `##` heading | **fixed** | `bin/kb-lint.php:568-579`: `$headEnd` is the collapsed head boundary (`:568-571`), the tail is sliced from the original `$at` (`:579`), and the separator is gated on `$headEnd > 0` (`:575-577`). Matrix in §3: `Body.\n\n## Section` → `…kb-index:end -->\n\n## Section` (one blank). Replayed round-2 `7dc27a0`: same input → `…kb-index:end -->\n\n\n## Section` (two blanks); master and round-1 → one. New test `tests/KnowledgeBase/KbLintScriptTest.php:273-295` asserts no `\n\n\n## Section\n` / `\n\n\n## Tag index`; it fails on `7dc27a0`. |
| F-8 | `normalizeTrailingNewline()` rewrote only the tail of a CRLF file, producing mixed line endings | **fixed** | `bin/kb-lint.php:604-608` returns early for any file containing `\r`; `:610` is back to `rtrim($contents, "\n") . "\n"`. Verified: an in-sync CRLF fixture with its final newline stripped is byte-identical after `--fix` (9 `\r\n`, last byte `0d`; no mixed endings introduced). Residual: the early return means #694's symptom (a stripped trailing newline) is still not repaired for CRLF inputs — but that is the documented LF-only contract (`:604-605`, `code-decision-3.md`), and the KB is LF-only. **No test pins this guard — new F-9.** |

F-1..F-5 stay fixed at HEAD (unchanged by the round-3 delta; re-verified in
passing: `writeIndex` write path `bin/kb-lint.php:585` still
`rtrim(implode("\n", $lines), "\n") . "\n"`; `normalizeTrailingNewline` is
`void`; `--fix` help/docblock/README consistent; only `assertStringEnds*` in the
file).

## 1. Helpers read (TAG INDEX)

Tags matching the diff (`bin`, `lint`, `tests`, `phpstan`, `php82`,
`knowledge-base`, `docs`):

- **FAQ-031** (`bin/` is inside linter scope) — respected: PHPStan and
  php-cs-fixer both cover `bin/`; both were run on the changed files (green).
- **FAQ-037** (`php82` minimum) — checked: `str_contains`, `rtrim`,
  `file_get_contents`/`file_put_contents`, list spread; no 8.3+ syntax. Clean.
- **DEC-008** (`composer lint` canonical; checks safe mid-cycle) — respected:
  `--fix` stays on `composer lint-fix`; no new repository-wide check.
- **DEC-009** / README rule 2 (single writer) — respected: this diff does not
  write `docs/helpers/`; proposals only (§8).
- **DEC-007** (coverage floor single source) — untouched; `bin/` is outside
  `<source>`, floor not lowered.
- **FAQ-032** (workflow sweep) — not triggered: no `.github/workflows/*` file is
  touched (`git diff --name-only master...HEAD`).
- **DEC-021** (changelog) — not triggered.

No documented decision is violated by this diff.

## 2. Checks run

| Check | Command | Result |
|---|---|---|
| Unit tests | `phpunit --no-coverage tests/KnowledgeBase/KbLintScriptTest.php` | OK — 25 tests, 280 assertions |
| KB sweep | `phpunit --no-coverage tests/KnowledgeBase/` | OK — 42 tests, 1167 assertions |
| PHPStan 8 | `phpstan analyse bin/kb-lint.php tests/KnowledgeBase/KbLintScriptTest.php` | No errors |
| php-cs-fixer | `fix --dry-run --diff --path-mode=intersection <2 files>` | 0 of 2 fixable |
| Rector | `rector process <2 files> --dry-run` | OK |
| Real tree | `php bin/kb-lint.php --fix` then `git status --porcelain` | exit 0, **no working-tree change** |
| Pin sweep | `grep -rln "kb-lint\|kbLint\|KbLint" tests/` | only `KnowledgeBaseTest.php` + `KbLintScriptTest.php`, both covered by the KB sweep |

`tests/KnowledgeBase/KnowledgeBaseTest.php` is the other class that pins the
script; it is inside the sweep above and green. No coverage floor was changed.

## 3. F-7 verification — create path against every input state at HEAD

Replayed `--fix` in scratch sandboxes (valid entry, index removed), varying only
the tail/heading. Bytes between the last body line and the first `##` section:

| Input state | master | round-2 `7dc27a0` | HEAD |
|---|---|---|---|
| no `##` heading, 0 trailing `\n` | `Body.\n## Tag index` | `Body.\n\n## Tag index` | `Body.\n\n## Tag index` |
| no `##` heading, 1/2/3 trailing `\n` | `Body.\n\n\n## Tag index` (2 blanks) | `Body.\n\n## Tag index` | `Body.\n\n## Tag index` |
| `## Section` **no** blank before | `Body.\n## Tag index …\n\n## Section` | `Body.\n\n## Tag index …\n\n## Section` | `Body.\n\n## Tag index …\n\n## Section` |
| `## Section` **one** blank before | `Body.\n\n## Tag index …\n\n## Section` | `Body.\n\n## Tag index …\n\n\n## Section` ← regression | `Body.\n\n## Tag index …\n\n## Section` — **fixed** |
| `## Section` **two** blanks before | `Body.\n\n\n## Tag index …\n\n## Section` | `Body.\n\n## Tag index …\n\n\n\n## Section` | `Body.\n\n## Tag index …\n\n## Section` — **fixed** |
| file starts with blanks, then `## Section` | leading blanks kept, `\n\n\n## Tag index …` | leading blanks kept | leading blanks **dropped**; output starts `## Tag index …\n\n## Section` |
| `##` heading at line 1 (`$at === 0`) | `## Tag index …\n\n## Section` (correct) | same | same (correct) |
| only blanks / empty file | index at top, no leading blank | same | same (correct) |

- Every heading-present and heading-absent state now yields exactly one blank
  separator, and the heading-absent branch (F-1) stays fixed.
- The one behaviour change beyond the finding: a file whose first `##` heading is
  preceded only by blank lines now has those leading blanks dropped instead of
  kept-and-separated. That is the correct reading of "exactly one blank between
  the index and what follows" when there is nothing before the index (head is
  empty, so there is nothing to separate). No content is lost. Not a finding.
- Second `--fix` pass is byte-identical for all 12 states (idempotent).

## 4. F-8 verification — LF-only normalisation

- `bin/kb-lint.php:606` `str_contains($contents, "\r")` → `return;` for CRLF /
  lone-CR files; `:610` only collapses LF tails.
- In-sync CRLF fixture (9 `\r\n`) with the final newline stripped: unchanged by
  `--fix` (`git`-equivalent byte compare), so no mixed `\r\n` + `\n` file can be
  produced by this path. Fixed.
- Out-of-sync CRLF files: `readLines()` already normalised `\r\n`→`\n` before
  `writeIndex()` in master too, so `--fix` converting such a file wholly to LF
  is pre-existing, not introduced here.

## 5. F-6 judgment

F-6 is still reproducible at HEAD (§0) and unchanged by round 3. Deferring it is
**acceptable for #694**: it is a distinct pre-existing defect (marker-less file
that already has a `## Tag index` heading) needing marker-vs-heading
reconciliation or a new lint error, plus its own tests; #694 is the trailing-
newline issue. The condition is the same as round 2: the deferral is only
legitimate if step 14 actually opens the issue with the reproduction
(`Body.\n\n## Tag index\n\nSome stale text.\n` → duplicate heading, exit 0).
Until that issue exists it remains an open finding on the record. Round 3 does
not worsen it.

## 6. New findings

### F-9 — the LF-only guard added to fix F-8 has no regression test — low

`bin/kb-lint.php:604-608`. Round 3 added the CRLF early return that is now the
*only* thing preventing `normalizeTrailingNewline()` from producing mixed line
endings; deleting or inverting it re-introduces round-2's F-8 silently and no
test in the suite notices. `grep -rn '\\r' tests/KnowledgeBase/` finds no CRLF
fixture (only `KnowledgeBaseTest.php:318`, a `\r\n`→`\n` read helper).

Suggested test (in the same file as the other `--fix` newline tests, so the same
class of defect is gated in this PR rather than reported again): build the valid
fixture, run `--fix` once so the index is in sync, convert the file to CRLF, strip
the final newline, run `--fix` again, and assert the bytes are unchanged (i.e.
still contains `\r\n` and does not end in a bare `\n`).

Check that would catch it: the test itself. Neither PHPStan, php-cs-fixer nor
Rector can see a missing branch test. `bin/` is outside coverage `<source>`, so
the floor does not force it.

## 7. Other checks (no finding)

- PSR-4 / Bundle conventions: `bin/kb-lint.php` is a script; the test class
  stays `final`, `@coversNothing`, same namespace. Unchanged.
- Type correctness: PHPStan level 8 clean. `$headEnd`/`$at` are bounded by
  `[0, count($lines)]` with `$headEnd <= $at`; the `?? ''` guard on
  `$lines[$headEnd - 1]` is still correct.
- Error handling: `file_get_contents` false is handled; missing KB files are
  rejected in `main()` before `writeIndex`/`normalizeTrailingNewline`.
- `normalizeTrailingNewline()` re-reads a file that `writeIndex()` just wrote;
  the content is already normalised so the call is a cheap no-op. Not a defect.
- Security: no HTTP input, no subprocess, no unserialize; paths derive only from
  `--root` / `KB_LINT_ROOT`.
- Docs: file docblock, `printUsage()` and `bin/README.md:168` agree; no stale
  "regenerate the tag index" string remains.

## 8. Candidate helper entries (proposal only — do NOT append)

Round 1/2 candidates remain valid. One addition for F-9:

### Candidate E — a line-ending guard needs a fixture that exercises it

- **Tags:** `bin`, `lint`, `tests`, `php-strings`
- **Trigger:** adding an early return that special-cases CRLF/binary content in a
  text-normalising script.
- **Paragraph:** when a normaliser is scoped to LF-only files by returning early
  on `str_contains($contents, "\r")`, that guard *is* the fix — nothing else
  prevents mixed line endings. `bin/` sits outside coverage `<source>`, so no
  gate notices if it is dropped. Pair the guard with a fixture that writes a CRLF
  file (in-sync index, stripped trailing newline) and asserts `--fix` leaves it
  byte-identical. Verified in #694 review round 3 (F-9): the F-8 fix shipped with
  no CRLF fixture.

## 9. Verdict

**Clean** with one new low finding. F-7 is genuinely fixed (matrix §3, new test
fails on round-2, passes now) and F-8 is genuinely fixed (CRLF file left
untouched). No regression: every create-path input state yields exactly one blank
separator and the operation is idempotent; the append-branch F-1 fix is intact.
All gates are green and no gate was lowered.

- F-6: open, deliberately deferred — acceptable only if the issue is opened at
  step 14.
- F-7: fixed.
- F-8: fixed (residual LF-only scope is the documented contract).
- F-9: new, low — missing CRLF regression test for the F-8 guard.

Because F-9 is a `low` test-coverage gap and not a correctness defect, the branch
can ship; add the CRLF fixture in this PR if a further round is run, otherwise it
becomes a follow-up.
