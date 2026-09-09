# Review Round 1 — Issue #588: composer.json dependency hygiene

Branch: `chore/issue-588-composer-json-ships-an-unused-dependency` @ `95f233e`
Scope (vs `origin/master`): `composer.json`, `.github/workflows/tests.yaml`, `CHANGELOG.md`
(+ coder proof files `code-decision-1.md`, `findings-coder.md` in the same commit — read, not reviewed as product code)

Verdict: **no blocking findings** — 0 high, 0 medium, 2 low, 1 nit. R1-1 is a two-line swap;
R1-2/R1-3 are follow-ups. Safe to merge after R1-1 (or with R1-1 fixed in a fast follow-up round).

## 1. Helpers compliance (tag index first, then matching entries only)

Tags matching this diff: `ci`, `github-actions`, `yaml`, `tests`, `process`, `coverage`,
`docs`, `markdown`, `lint`, `policy`. Entries read: FAQ-032, FAQ-033, FAQ-034, FAQ-011,
DEC-007, DEC-008, DEC-012, DEC-009. **No violations.**

- FAQ-032 (workflow-pin sweep): complied. `grep -rl 'tests.yaml' tests/` →
  `GithubWorkflowsTest.php`, `CoverageCiGateTest.php`; ran both **plus**
  `ComposerConfigTest` and `ChangelogStructureTest` (they pin `composer.json`/`CHANGELOG.md`).
  71 tests, 329 assertions, green.
- FAQ-033 (concurrency per-ref): diff touches neither triggers nor the `concurrency` block;
  verified group is still `${{ github.workflow }}-${{ github.ref }}` with PR-only cancel. No violation.
- FAQ-034 (YAML 1.1 `on:` gotcha): validated by raw-text reasoning + existing regex-pinning
  tests, not a YAML deserializer. No violation.
- FAQ-011 / DEC-007 (coverage floor lives in `composer.json`): `coverage:check` untouched;
  `CoverageCiGateTest` green. No violation.
- DEC-008 (lint is canonical): no new check added, nothing to wire. N/A.
- DEC-012 (no raw `<...>` in Markdown): CHANGELOG entry scanned — no angle brackets;
  `bin/check-changelog.php` green. No violation.
- DEC-009 (single writer): proposing candidate entries below, not appending.

## 2. Prior-round findings

`docs/proof_of_work/0588-composer-json-deps/findings-review.md` does **not** exist yet
(Round 1 — nothing left open by an earlier round). Created it this round with R1-1…R1-3.
Coder-reported items F-1…F-6 (`findings-coder.md`) are triaged in §5, not prior-round findings.

## 3. Findings (new this round)

### R1-1 — `composer.json:37-38`: require block is not alphabetically sorted (nit)
`symfony/deprecation-contracts` is listed before `symfony/dependency-injection`, but
byte-wise `dependency-injection` < `deprecation-contracts` (`dep-e…` < `dep-r…`).
Verified: PHP `sort()` over the 8 `symfony/*` keys returns `dependency-injection` first →
current order is `NOT-SORTED`. This contradicts `code-decision-1.md`'s "kept alphabetically
sorted (`sort-packages: true`)" claim. No functional impact — `composer validate --strict`
is green (it does not enforce order) — convention-only. Fix: swap the two lines.
Check that could have caught it: a `ComposerConfigTest` sorted-keys assertion, or
`composer normalize --dry-run` in lint. Class not seen before → reported, not written.

### R1-2 — No test pins the matrix `sed` exclusion against `composer.json` (low)
Endorses coder F-5. Both `Update Symfony constraints` steps embed a regex+exclusion that must
stay in sync with every `symfony/*` line's versioning scheme; today nothing fails locally if
they drift — the breakage appears only as red matrix legs after push (the S-1 shape: any
future `*-contracts` require without an exclusion breaks all 9 legs). Wanted gate: a
`GithubWorkflowsTest` test that applies the workflow's rewrite logic to `composer.json` and
asserts every non-`^6.4|^7.0|^8.0`-style `symfony/*` entry is covered by a `sed` exclusion.
Class not seen before → reported + candidate helpers entry (C-1), not written.

### R1-3 — `Symfony\Contracts\*` imports still resolve transitively (low, follow-up)
Endorses coder F-2 (verified: 7 files import `Symfony\Contracts\EventDispatcher\*`,
1 file imports `Symfony\Contracts\Service\ResetInterface`; only 2 files import
`Symfony\Component\EventDispatcher`). The CHANGELOG claim "Declared the Symfony packages the
bundle imports directly" is therefore slightly overstated — the `-contracts` packages remain
undeclared. No functional defect today (`event-dispatcher-contracts` is a hard require of
`symfony/event-dispatcher`; `service-contracts` arrives via `dependency-injection`), and the
coder's call to let the planned `composer-require-checker` step settle it is reasonable.
Note for that follow-up: both contracts packages are 2.x/3.x-versioned, so declaring them
requires extending the `tests.yaml` `sed` exclusion the same way. Check that would settle
it: `composer-require-checker` (already in the issue's acceptance criteria).

## 4. Verifications (with evidence)

- **Removal safe.** Repo-wide grep for `mime-type-detection|MimeTypeDetection` → only
  `CHANGELOG.md` and the coder proof docs. Zero `src/`/`tests/`/`bin/` references. (The
  `tests/StreamedBinaryFileResponseTest.php` `symfony/mime` guards are a different package —
  see coder F-4, pre-existing/out of scope.)
- **Additions justified.** `HttpFoundation` imported by 10 `src/` files (issue said 9; the
  10th is a newer response-path class — coder F-1, no diff text states a count, no action);
  `EventDispatcher` component by 2 files; `trigger_deprecation()` at `src/Utils.php:76`
  (needs `deprecation-contracts`).
- **Constraint style consistent.** New `http-foundation`/`event-dispatcher` use
  `^6.4|^7.0|^8.0`, identical to all 5 sibling `symfony/*` entries. New
  `^2.5|^3.0` ≡ siblings' transitive `^2.5|^3` (installed tree: console/config/
  event-dispatcher/http-kernel v8.1.0 all require `^2.5|^3`; installed contracts v3.7.0
  satisfies the new constraint).
- **`sed` correct, both steps.** `grep -c deprecation-contracts tests.yaml` = 4
  (2 comments + 2 `sed` lines); diff shows both `Update Symfony constraints` hunks.
  `/pattern/!s/…/…/g` is valid GNU `sed` address-negated substitution (CI runs
  ubuntu-latest). PHP simulation of the exact regex for all three legs (`6.4.*`, `7.4.*`,
  `8.0.*`): all 8 framework-versioned lines rewrite (7× `require` + `framework-bundle` in
  `require-dev`), `deprecation-contracts` skipped, `allow-plugins` `"symfony/runtime": true`
  correctly untouched (regex requires an opening version quote). Replacement
  `\1$SYMFONY_VERSION` is safe (leg versions contain no `&`, `\`, `/`); `\1` is unambiguous
  in `sed` (single-digit backrefs — unlike PCRE `$16`).
- **CHANGELOG valid.** Entry under `[Unreleased]` → `### Changed`, `[#588]` link in prose
  (outside inline code), Keep-a-Changelog sections intact, `php bin/check-changelog.php` green.
- **`suggest.ext-zip` correct as suggest (not require).**
  `SfxDownloader::openArchive()` guards `\ZipArchive` with `class_exists()`; CI already
  installs the `zip` extension. Append-at-end matches the pre-existing unsorted `suggest` style.
- **Automated gates green.** `composer validate --strict` → exit 0;
  `php bin/check-changelog.php` → OK; `php bin/kb-lint.php` → OK (1 pre-existing
  `faq.md` line-budget warning, unrelated); sweep
  `GithubWorkflowsTest|CoverageCiGateTest|ComposerConfigTest|ChangelogStructureTest` →
  OK (71 tests, 329 assertions), including `testComposerAuditNoDevIsClean` (new deps add
  no production advisories).
- **No stale references.** No `composer.lock` tracked; `README.md`/`UPGRADE.md`/`docs/*.md`
  contain no `mime-type-detection`/`ext-zip` mentions needing updates. No `UPGRADE.md` entry
  needed (leaf removal, no bundle-API BC break). PSR-12 / types / error handling: N/A (no PHP changed).

## 5. Triage of coder-reported items

- F-1 (http-foundation count 10 vs 9): verified 10, but no shipped file states a count → not a finding.
- F-2 (`Symfony\Contracts\*` transitive): endorsed → R1-3 (low, require-checker follow-up).
- F-3 (unguarded `trigger_deprecation`): pre-existing, out of scope; declaring the dep is the
  issue-prescribed fix → acknowledge, no action.
- F-4 (`symfony/mime` test-guard skips): pre-existing, out of scope → acknowledge, no action.
- F-5 (no `sed` pin test): endorsed → R1-2 (low) + candidate entry C-1.
- F-6 (dev-only Guzzle advisories): pre-existing, unrelated; `--no-dev` audit proven clean by
  the green sweep → acknowledge, no action.
- S-1/S-2/S-3: narrative; S-1's lesson is captured in candidate entry C-1.

## 6. Candidate docs/helpers entries (proposed, not written)

**C-1 — Matrix `sed` must exclude non-framework-versioned `symfony/*` packages.**
Tags: `ci`, `github-actions`, `tests`. Trigger: "adding a `symfony/*` require whose major
line does not track the framework (any `*-contracts` package)". The `tests.yaml` matrix
rewrites every `"symfony/*"` constraint to the leg version (`6.4.*`/`7.4.*`/`8.0.*`), but
`*-contracts` packages ship only 2.x/3.x — without a `/…/!` exclusion the rewrite names a
non-existent version and breaks dependency resolution on all nine legs (hit in #588 via
`symfony/deprecation-contracts`). Exclude with an address-negated `sed` plus a comment, in
**both** `Update Symfony constraints` steps; the wanted gate is a `GithubWorkflowsTest` pin
that replays the rewrite against `composer.json` (open since #588 review round 1).

**C-2 — `composer validate --strict` does not enforce `sort-packages` order.**
Tags: `ci`, `tests`. Trigger: "reordering or adding a `composer.json` require entry".
`sort-packages: true` is convention-only: validate stays green on a mis-sorted require block
(proven in #588, where `symfony/deprecation-contracts` landed before
`symfony/dependency-injection` despite `dep-e…` < `dep-r…` byte-wise). Keep `require` keys
byte-sorted by hand when editing; a `ComposerConfigTest` sorted-keys assertion (or
`composer normalize --dry-run`) would gate it.
