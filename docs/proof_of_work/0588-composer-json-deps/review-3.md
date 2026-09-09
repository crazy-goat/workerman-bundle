# Review Round 3 — Issue #588: composer.json dependency hygiene

Branch: `chore/issue-588-composer-json-ships-an-unused-dependency` @ `2dd8600`
Scope (vs `origin/master`): `composer.json`, `.github/workflows/tests.yaml`, `CHANGELOG.md`
(+ proof files `code-decision-1.md`, `findings-coder.md`, `findings-review.md`, `review-1.md`,
`review-2.md` — read; `code-decision-1.md`/`findings-coder.md` re-checked for staleness, see R2-1)
Delta since round 2 (`d00e0ed..2dd8600`): proof-docs only — the two R2-1 line fixes plus
the committed round-2 proof files. Zero shipped-code delta.

Verdict: **clean** — 0 high, 0 medium, 0 low, 0 nit. All prior items are fixed or
deliberately deferred as decided; no new findings. Safe to merge.

## 1. Helpers compliance (tag index first, then matching entries only)

Tags matching this diff: `ci`, `github-actions`, `yaml`, `tests`, `process`, `coverage`,
`docs`, `markdown`, `lint`, `policy`. Entries read: FAQ-032, FAQ-033, FAQ-034, FAQ-011,
DEC-007, DEC-008, DEC-012, DEC-009. **No violations.**

- FAQ-032 (workflow-pin sweep): complied. `grep -rl 'tests.yaml' tests/` →
  `GithubWorkflowsTest.php`, `CoverageCiGateTest.php`; ran both **plus**
  `ComposerConfigTest` and `ChangelogStructureTest` (they pin `composer.json`/`CHANGELOG.md`).
  71 tests, 329 assertions, green — identical to rounds 1–2.
- FAQ-033 (concurrency per-ref): `tests.yaml` untouched since round 1; group is still
  `${{ github.workflow }}-${{ github.ref }}` with PR-only cancel. No violation.
- FAQ-034 (YAML 1.1 `on:` gotcha): no YAML deserializer used; workflow validated via
  raw-text reasoning + the regex-pinning tests. No violation.
- FAQ-011 / DEC-007 (coverage floor lives in `composer.json`): `coverage:check` untouched;
  `CoverageCiGateTest` green. No violation.
- DEC-008 (lint is canonical): no new check added, nothing to wire. N/A.
- DEC-012 (no raw `<...>` in Markdown): CHANGELOG entry and proof files scanned —
  no raw angle-bracket tokens in prose; `bin/check-changelog.php` green. No violation.
- DEC-009 (single writer): round-1 candidates C-1, C-2 left for the retro; no new
  candidate this round (nothing learned that is reusable knowledge). Nothing appended here.
- Tag-level matches skipped on trigger mismatch (read index + trigger lines only, not bodies):
  FAQ-037 (trigger is new-PHP-syntax vs old minor — no PHP changed), FAQ-038 (trigger is
  `/proc` fail-closed testing), FAQ-030/FAQ-031/FAQ-035 (fork helpers, `bin/` scope,
  config bounds — none touched by this diff).

## 2. Prior-round findings (explicit verdicts)

- **R1-1 (nit, require block mis-sorted) — FIXED (unchanged since round 2).**
  `symfony/dependency-injection` at `composer.json:37` before
  `symfony/deprecation-contracts` at `:38`; re-verified `SORTED` with
  `array_values` + `sort(SORT_STRING)`, `strcmp` = `-13`. No `composer.json` delta
  since `d00e0ed`, so the fix stands.
- **R1-2 (low, no test pins the matrix `sed` exclusion) — STILL PRESENT, deliberately
  deferred by main-session decision (not a regression).** `git diff --name-only`
  shows no `tests/` file in the diff; the drift-only-surfaces-in-CI shape is unchanged.
  Follow-up GitHub issue, out of scope for this chore.
- **R1-3 (low, `Symfony\Contracts\*` still transitive + CHANGELOG overclaim) —
  PARTIALLY FIXED, as decided (unchanged since round 2).** The wording half is fixed:
  "Declared three Symfony packages the bundle imports directly" (`CHANGELOG.md:51`).
  The contracts half is still present by design (7 files import
  `Symfony\Contracts\EventDispatcher\*`, 1 imports `ResetInterface`; both packages
  still resolve transitively), deferred to the `composer-require-checker` follow-up.
- **R2-1 (nit, proof docs contradict the fixed tree) — FIXED by `2dd8600`.**
  `code-decision-1.md:22-24` now lists "dependency-injection, deprecation-contracts",
  matching `composer.json:37-38`; `findings-coder.md` S-1 now cites `:211-216`, which
  exactly covers the second `Update Symfony constraints` step
  (`- name:` at 211 through the `sed` line at 216; verified against the current file).
  Both changes confirmed in the `2dd8600` diff. No remaining proof-doc staleness:
  `review-1.md:61` quotes the old CHANGELOG wording correctly as a historical record.

## 3. Findings (new this round)

**No new findings.** The shipped-code diff is byte-identical to round 2 (proof-docs-only
delta), and the full verification sweep below is green.

## 4. Verifications (with evidence)

- **Prior verifications re-run, all still green.** `composer validate --strict` →
  exit 0 (no lock-hash refresh needed this round — local lock already in sync,
  `git status` clean); `php bin/check-changelog.php` → OK; sweep
  `GithubWorkflowsTest|CoverageCiGateTest|ComposerConfigTest|ChangelogStructureTest` →
  OK (71 tests, 329 assertions); `php bin/kb-lint.php` → OK (1 pre-existing `faq.md`
  line-budget warning, unrelated).
- **`sed` guards intact, both steps.** `grep -c deprecation-contracts tests.yaml` = 4
  (2 comments + 2 `sed` lines at 153/155 and 214/216); `2dd8600` did not touch `tests.yaml`.
  Rewrite re-simulated with the portable `[[:space:]]` stand-in (BSD `sed` lacks `\s`
  per coder S-2): every `symfony/*` constraint pins to `6.4.*` except
  `symfony/deprecation-contracts`, which keeps `^2.5|^3.0`.
- **Whole-block sort sanity.** `require` symfony-block `SORTED`; `require-dev` `SORTED`;
  the `php`-before-`ext-*` platform convention matches `origin/master` — not a finding.
- **No stale product references.** No `mime-type-detection`/`MimeTypeDetection` in
  `src/`, `tests/`, `bin/`, `composer.json`, or `.github/`; no `composer.lock` tracked;
  no `README.md`/`UPGRADE.md` updates needed (unchanged since round 1).
- **Type correctness / error handling / PSR-12:** N/A — `git diff --name-only` confirms
  zero `src/`, `tests/`, or `bin/` files in the diff. **Missing tests:** only the
  already-deferred R1-2. **Outdated documentation:** none.

## 5. Candidate docs/helpers entries

None new this round. Round-1 candidates C-1 (matrix `sed` exclusion) and C-2
(`composer validate` ignores sort order) remain proposed for the retro step; round 3
surfaced no reusable lesson, and per DEC-009 nothing is appended here.
