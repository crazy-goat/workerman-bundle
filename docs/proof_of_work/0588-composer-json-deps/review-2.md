# Review Round 2 — Issue #588: composer.json dependency hygiene

Branch: `chore/issue-588-composer-json-ships-an-unused-dependency` @ `d00e0ed`
Scope (vs `origin/master`): `composer.json`, `.github/workflows/tests.yaml`, `CHANGELOG.md`
(+ proof files `code-decision-1.md`, `findings-coder.md`, `findings-review.md`, `review-1.md`
— read; `code-decision-1.md`/`findings-coder.md` checked for staleness, see R2-1)
Delta since round 1 (`95f233e..d00e0ed`): two-line `composer.json` swap, one-word
CHANGELOG narrowing, plus the committed round-1 proof files.

Verdict: **findings, non-blocking** — 0 high, 0 medium, 0 low, 1 new nit (R2-1,
proof-doc only). All round-1 items are fixed or deliberately deferred as decided.
Safe to merge; R2-1 is a two-line proof-doc touch-up the main session can take or leave.

## 1. Helpers compliance (tag index first, then matching entries only)

Tags matching this diff: `ci`, `github-actions`, `yaml`, `tests`, `process`, `coverage`,
`docs`, `markdown`, `lint`, `policy`. Entries read: FAQ-032, FAQ-033, FAQ-034, FAQ-011,
DEC-007, DEC-008, DEC-012, DEC-009. **No violations.**

- FAQ-032 (workflow-pin sweep): complied. `grep -rl 'tests.yaml' tests/` →
  `GithubWorkflowsTest.php`, `CoverageCiGateTest.php`; ran both **plus**
  `ComposerConfigTest` and `ChangelogStructureTest` (they pin `composer.json`/`CHANGELOG.md`).
  71 tests, 329 assertions, green — identical to round 1.
- FAQ-033 (concurrency per-ref): `tests.yaml` delta touches only the two `sed` steps;
  group is still `${{ github.workflow }}-${{ github.ref }}` with PR-only cancel. No violation.
- FAQ-034 (YAML 1.1 `on:` gotcha): no YAML deserializer used; workflow validated via
  raw-text reasoning + the regex-pinning tests. No violation.
- FAQ-011 / DEC-007 (coverage floor lives in `composer.json`): `coverage:check` untouched;
  `CoverageCiGateTest` green. No violation.
- DEC-008 (lint is canonical): no new check added, nothing to wire. N/A.
- DEC-012 (no raw `<...>` in Markdown): CHANGELOG entry and this round's proof files
  scanned — angle-bracket tokens only in fenced blocks or backticks;
  `bin/check-changelog.php` green. No violation.
- DEC-009 (single writer): candidate entries from round 1 (C-1, C-2) left for the retro;
  no new candidate this round (R2-1 is per-issue scratch staleness, not reusable knowledge).
- Tag-level matches skipped on trigger mismatch (read index + trigger lines only, not bodies):
  FAQ-037 (trigger is new-PHP-syntax vs old minor — no PHP changed), FAQ-038 (trigger is
  `/proc` fail-closed testing), FAQ-030/FAQ-031/FAQ-035 (fork helpers, `bin/` scope,
  config bounds — none touched by this diff).

## 2. Prior-round findings (explicit verdicts)

- **R1-1 (nit, require block mis-sorted) — FIXED.** `d00e0ed` swapped the two lines;
  current order is `symfony/dependency-injection` (`composer.json:37`) before
  `symfony/deprecation-contracts` (`composer.json:38`). Re-verified with a correct
  comparison (`array_values` + `sort(SORT_STRING)`): `SORTED`, and
  `strcmp` returns `-13`, confirming `dep-e…` < `dep-r…` byte-wise. Note: round 1's
  one-liner compared `array_filter` output (key-preserving) with `===` against a
  `sort()`ed array (re-indexed), which can never be equal — the method always printed
  `NOT-SORTED`, but the byte-order reasoning behind the finding was independently
  correct, so R1-1 was a real finding and is now genuinely fixed.
- **R1-2 (low, no test pins the matrix `sed` exclusion) — STILL PRESENT, deliberately
  deferred by main-session decision (not a regression).** `git diff --name-only`
  shows no `tests/` file in the diff; the breakage shape is unchanged (a future
  non-framework-versioned `symfony/*` require without a `sed` exclusion still fails
  only as red matrix legs post-push). Main session's call stands: first-seen class,
  low risk, new test infrastructure out of scope for this chore — follow-up issue.
- **R1-3 (low, `Symfony\Contracts\*` still transitive + CHANGELOG overclaim) —
  PARTIALLY FIXED, as decided.** The wording half is fixed: `d00e0ed` narrowed the
  entry to "Declared three Symfony packages the bundle imports directly"
  (`CHANGELOG.md:51`, verified in the commit diff and the current file), so the
  overclaim is gone. The contracts half is still present by design (7 files import
  `Symfony\Contracts\EventDispatcher\*`, 1 imports `ResetInterface`; both packages
  still resolve transitively) and stays deferred to the planned
  `composer-require-checker` follow-up, which must also extend the `tests.yaml`
  `sed` exclusion (both are 2.x/3.x-versioned).

## 3. Findings (new this round)

### R2-1 — Proof docs contradict the fixed `composer.json` (nit, docs-only)

- `docs/proof_of_work/0588-composer-json-deps/code-decision-1.md:22-24` still lists the
  require order as "config, console, **deprecation-contracts, dependency-injection**, …"
  — the pre-R1-1-fix order. Post-fix `composer.json:37-38` has them swapped, so the
  sentence and its "kept alphabetically sorted" claim now describe a file that no longer
  exists. Fix: swap the two names in that sentence (main session, one line).
- `docs/proof_of_work/0588-composer-json-deps/findings-coder.md` S-1 cites
  `.github/workflows/tests.yaml:150-153` and `:209-212`; the second step now starts at
  line 211 (the first hunk's two comment lines shifted it; pre-change numbering was
  150/209, post-change 150/211). Fix: `:209-212` → `:211-216` (or drop line numbers).
- Scope: proof docs are not shipped code — no user, CI, or packaging impact. Not edited
  here: they are another agent's record and the main session owns them.
- Check that could have caught it: none plausible — prose staleness in per-issue scratch
  docs is not machine-checkable. Class first seen here → reported, not written.
  (`review-1.md:61` quotes the old CHANGELOG wording, but correctly as a historical
  round-1 record — not stale.)

## 4. Verifications (with evidence)

- **Round-1 verifications re-run, all still green.** `composer validate --strict` →
  exit 0 (after `composer update --lock` refreshed the gitignored local lock hash —
  the known coder S-3 shape; `git status` clean, nothing committed);
  `php bin/check-changelog.php` → OK; sweep
  `GithubWorkflowsTest|CoverageCiGateTest|ComposerConfigTest|ChangelogStructureTest` →
  OK (71 tests, 329 assertions); `php bin/kb-lint.php` → OK (1 pre-existing `faq.md`
  line-budget warning, unrelated).
- **`sed` guards intact, both steps.** `grep -c deprecation-contracts tests.yaml` = 4
  (2 comments + 2 `sed` lines at 153/155 and 214/216); `d00e0ed` did not touch `tests.yaml`.
- **Whole-block sort sanity.** `require-dev` is byte-sorted; `require` is byte-sorted
  except the pre-existing `php`-before-`ext-*` platform convention, which matches
  `origin/master` and composer's own sorter — not a finding.
- **No stale product references.** No `mime-type-detection`/`MimeTypeDetection` in
  `src/`, `tests/`, `bin/`, `composer.json`, or `.github/`; no `composer.lock` tracked;
  no `README.md`/`UPGRADE.md` updates needed (unchanged since round 1).
- **Type correctness / error handling / PSR-12:** N/A — `git diff --name-only` confirms
  zero `src/`, `tests/`, or `bin/` files in the diff. **Missing tests:** only the
  already-deferred R1-2. **Outdated documentation:** R2-1 only.

## 5. Candidate docs/helpers entries

None new this round. Round-1 candidates C-1 (matrix `sed` exclusion) and C-2
(`composer validate` ignores sort order) remain proposed for the retro step; R2-1's
class (per-issue proof-doc staleness) is scratch hygiene, not reusable knowledge,
and per DEC-009 nothing is appended here.
