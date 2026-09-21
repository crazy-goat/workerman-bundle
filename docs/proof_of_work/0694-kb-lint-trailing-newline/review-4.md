# review-4 — #694 kb-lint `--fix` trailing newline + index separator (round 4, final)

Diff reviewed: `git diff master...HEAD` on
`refactor/issue-694-kb-lint-php-fix-does-not-normalize-the-t` (HEAD `9272588`,
"test(bin): cover LF-only normalisation of CRLF files (#694)"). Round-4 delta is
`ba46f16..HEAD` and touches only
`tests/KnowledgeBase/KbLintScriptTest.php` + proof-of-work files.

Files in the branch diff:
- `bin/kb-lint.php`
- `tests/KnowledgeBase/KbLintScriptTest.php`
- `bin/README.md`
- `docs/proof_of_work/0694-kb-lint-trailing-newline/`

## 0. Earlier findings — adjudication

`findings-review.md` was read before anything else; every round-1..3 entry is
adjudicated in the appended round-4 table. Summary:

| # | Status in round 4 | Evidence |
|---|---|---|
| F-1 | **fixed** (intact) | `writeIndex()` create path `:568-571`, `:579`; `testFixCollapsesExtraTrailingNewlinesBeforeACreatedIndex` green. |
| F-2 | **fixed** | `normalizeTrailingNewline(): void` at `:596`; docblock says write-only-when-changed. |
| F-3 | **fixed** | file docblock `:17-18`, `printUsage()` `:625-626`, `bin/README.md:168` all mention the newline normalisation. |
| F-4 | **fixed** | only `assertStringEndsWith`/`assertStringEndsNotWith` in the test file. |
| F-5 | **fixed/superseded** | CRLF files are intentionally untouched; LF tails collapse at `:610`. |
| F-6 | **still present — deliberately deferred** | Reproduced at HEAD (§4); test-only delta does not change it. |
| F-7 | **fixed** (intact) | `$headEnd`/`$at` split `:568-579`; create-path matrix in review-3 §3 applies unchanged; its test is green. |
| F-8 | **fixed** (now pinned) | `:606` CRLF early return; new test (F-9) guards it. |
| F-9 | **fixed** | `testFixLeavesCrlfFilesUntouched` `:297-312`; mutation-verified (§3). |

No finding was deleted; F-6 remains on the record as the only open item.

## 1. Helpers read (TAG INDEX)

Tags matching the diff (`bin`, `lint`, `tests`, `php82`, `knowledge-base`,
`docs`):

- **FAQ-031** (`bin/` is inside linter scope) — respected: PHPStan and
  php-cs-fixer cover `bin/`; both run green on the changed files.
- **FAQ-037** (`php82` minimum) — checked: the new test uses only
  `str_replace`, `rtrim`, PHPUnit assertions; no 8.3+ syntax. Clean.
- **DEC-008** (`composer lint` canonical) — respected: `--fix` stays on
  `composer lint-fix`; no new repository-wide check.
- **DEC-009** (single writer for the KB) — respected: this diff proposes helper
  entries only; it does not write `docs/helpers/`.
- **DEC-007** (coverage floor single source) — untouched; `bin/` is outside
  `<source>`, floor not lowered.
- **FAQ-032** (workflow sweep) — not triggered: `git diff --name-only
  master...HEAD` contains no `.github/workflows/*` file.
- **DEC-021** (changelog) — not triggered.

No documented decision is violated by this diff.

## 2. Checks run

| Check | Command | Result |
|---|---|---|
| Unit tests | `phpunit --no-coverage tests/KnowledgeBase/KbLintScriptTest.php` | OK — 26 tests, 293 assertions |
| KB sweep | `phpunit --no-coverage tests/KnowledgeBase/` | OK — 43 tests, 1180 assertions |
| PHPStan 8 | `phpstan analyse bin/kb-lint.php tests/KnowledgeBase/KbLintScriptTest.php` | No errors |
| php-cs-fixer | `fix --dry-run --diff --path-mode=intersection <2 files>` | 0 of 2 fixable |
| Rector | `rector process <2 files> --dry-run` | OK |
| Real tree | `php bin/kb-lint.php --fix` then `git status --porcelain` | exit 0, **no working-tree change** (2 pre-existing budget warnings) |
| Pin sweep | `grep -rln "kb-lint\|kbLint\|KbLint" tests/` | only `KnowledgeBaseTest.php` + `KbLintScriptTest.php`, both in the sweep above |

No coverage floor, PHPStan level or lint rule was changed or lowered.

## 3. F-9 verification — the CRLF guard is genuinely pinned

Mutation run: copied the repo (source + tests + `phpunit.xml` + `tests/App`
bootstrap) to a scratch dir, deleted the `if (str_contains($contents, "\r"))
{ return; }` block at `bin/kb-lint.php:606`, and ran the new test.

- Baseline (unmutated copy): 26 tests pass.
- Mutated copy: `testFixLeavesCrlfFilesUntouched` **fails** with
  `a CRLF file must be left byte-for-byte untouched` — the tail is rewritten to
  a bare `\n` (the F-8 regression).

So the test fails exactly when the behaviour it guards regresses. `read()` is a
raw `file_get_contents` (`:655-661`), so the byte-level `assertSame` is not
normalising line endings. The fixture is in-sync (otherwise `writeIndex()` would
convert it to LF and the test would fail for a second reason), which is what
isolates `normalizeTrailingNewline()`. **F-9 fixed.**

## 4. F-6 verification — still present, deferral unchanged

Reproduced at HEAD in a scratch sandbox (valid entries, `## Tag index` heading
kept, only the `<!-- kb-index:start/end -->` markers removed):

```
input:  …\n## Tag index\n\n- `alpha` — FAQ-001\n\n## Section\n…
--fix:   exit 0, "tag index created"
output: two `## Tag index` headings (lines 3 and 9)
kb-lint: exit 0
```

Unchanged by round 4 (the delta is a test + proof of work). Deferring remains
**acceptable for #694**: it is a distinct pre-existing defect requiring
marker-vs-heading reconciliation or a new lint error plus its own tests, and it
is not the trailing-newline subject. The condition is unchanged from rounds 2-3:
the deferral is legitimate only if step 14 opens the issue with this
reproduction; until then it is an open finding on the record. Round 4 does not
worsen it.

## 5. New findings

**None.** The round-4 delta adds one test; it is correct, byte-level, fails on
the mutation it targets, and uses the file's established assertion helpers. No
new correctness, style, typing, security or documentation issue was found in the
branch diff, and no earlier finding regressed.

On convergence: this is round 4, and the only open item (F-6) is a deliberately
deferred, pre-existing defect outside #694's subject. No new issue is raised —
re-reporting F-6 or spinning another round would not change the outcome. The
change is ready to ship once step 14 files F-6.

## 6. Other checks (no finding)

- PSR-4 / Bundle conventions: `bin/kb-lint.php` is a script; the test class stays
  `final`, `@coversNothing`, same namespace. Unchanged.
- Type correctness: PHPStan level 8 clean; `$headEnd`/`$at` remain bounded with
  `$headEnd <= $at`.
- Error handling: `file_get_contents` false is handled in both helpers; missing
  KB files are rejected in `main()` before either helper runs.
- Security: no HTTP input, no subprocess, no unserialize; paths derive only from
  `--root` / `KB_LINT_ROOT`.
- Docs: file docblock, `printUsage()` and `bin/README.md` agree; no stale
  "regenerate the tag index" string remains.
- Real-tree idempotence: `--fix` reports `0 stale`, exit 0, no diff.

## 7. Candidate helper entries (proposal only — do NOT append)

Round 1/2/3 candidates stand. No new candidate this round; F-9's "Candidate E —
a line-ending guard needs a fixture that exercises it" (review-3 §8) is the
relevant one and is now satisfied in this PR, which is exactly the pattern worth
recording.

## 8. Verdict

**Clean — ready to ship.** F-9 is genuinely fixed (mutation-verified §3) and
there is no regression: all gates green, all round-1..3 fixes intact, the
real-tree `--fix` is idempotent and no gate was lowered.

- F-1..F-5, F-7, F-8: fixed, intact at HEAD.
- F-9: fixed in this PR.
- F-6: the only open finding — deliberately deferred, acceptable for #694 **only
  if step 14 opens the follow-up issue with the reproduction in §4**.
