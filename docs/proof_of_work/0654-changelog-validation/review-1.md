# review-1.md — #654 (structural CHANGELOG.md validation wired into composer lint)

Reviewer: review-critical, round 1. Diff under review: `origin/master..HEAD`
(f7e08c1 + c54cde4). `findings-review.md` did not exist before this round —
this is its first entry set.

## Verdict

**APPROVE (with comments).** No high-severity findings. The validator logic is
correct on every path I probed (11 adversarial fixtures), all local gates are
green, and the DEC-008 wiring is exactly as prescribed. The comments are a
medium finding on test coverage gaps (several validator behaviors are unpinned,
so specific regressions would pass CI) plus low/nit items on latent parser edge
cases and one proof-of-work inaccuracy.

## What I verified

### Method compliance

- Read `docs/helpers/faq.md` and `docs/helpers/decisions.md` via the tag index
  only. Entries read: DEC-006..DEC-012 (decisions), FAQ-011, FAQ-015, FAQ-030,
  FAQ-031, FAQ-032, FAQ-033, FAQ-034 (faq).
- `findings-review.md` did not exist at round start (round 1 confirmed via
  directory listing before any writes).

### Decision/FAQ conformance

- **DEC-008** (lint is the canonical entry point): compliant. One wiring edit
  in `composer.json` appends `php bin/check-changelog.php` to `lint`; the
  standalone `changelog:check` script is present only as the convenience alias
  DEC-008 allows. Verified the two indirect consumers by reading them:
  `.github/workflows/tests.yaml` Lint job runs `composer lint` ("Run code style
  checks" step), and `bin/install-git-hook.php` embeds `composer lint || exit 1`
  in the pre-push hook — so the single composer.json edit reaches both, as the
  coder claims. Also confirmed the "safe to run at any point in a cycle" bar:
  like phpstan/cs-fixer, the check fails only when the tree is genuinely
  invalid; the frozen legacy list keeps historical entries from failing it.
- **DEC-009** (single-writer knowledge base): respected — this review proposes
  candidates below and does not touch `docs/helpers/`.
- **DEC-012** (backtick angle-bracket placeholders): compliant — all new prose
  in `bin/README.md`, `docs/workflow.md` and the CHANGELOG entry backticks
  tokens like `## [x.y.z] - YYYY-MM-DD`.
- **FAQ-031** (`bin/` is inside linter scope): honored — phpstan and
  php-cs-fixer were run against the new script directly (both clean).
- **FAQ-032** (multiple tests can pin the same file): swept — no other test
  pins the `lint` array or CHANGELOG.md structure. `tests/ComposerConfigTest.php`
  pins audit config/description/keywords only; `tests/CoverageCiGateTest.php`
  pins the coverage script only; `tests/BinDirectoryTest.php` pins the hook's
  `composer lint || exit 1` (still satisfied, no component enumeration);
  `tests/Process/ProcessDocsTest.php` pins `docs/process-changelog.md`, an
  unrelated file. Nothing stale was left behind by the diff.

### Gates executed locally

- `php bin/check-changelog.php` on the real tree → `OK`, exit 0.
- `vendor/bin/phpunit --filter ChangelogStructureTest` → 14/14 methods green
  (18 total including the repo's FinalClass/NamespaceConvention metadata tests).
- `vendor/bin/phpstan analyse bin/check-changelog.php tests/ChangelogStructureTest.php`
  → no errors (level per project config).
- `vendor/bin/php-cs-fixer fix --dry-run --config=…` on both files → clean.
- Full `composer lint` end-to-end → green; the only warning is the pre-existing
  faq.md line-budget advisory already recorded as coder F-3.

### Adversarial probes (synthetic fixtures under the session temp dir)

| Probe | Result |
| --- | --- |
| Empty file | 3 violations (no headings / no Unreleased / no released) — correct, but see F-R1: untested |
| Only `[Unreleased]` | fails "no released version headings" — coder F-6 behavior confirmed live |
| Duplicate version `## [0.26.0]` twice | flagged via the `>= 0` comparison — correct, but see F-R1: untested |
| `## [Unreleased] ` (trailing space) | flagged, but with two misleading messages — F-R6 |
| Unknown subheading `### Fix` | silently valid — F-R1/F-R2 family |
| Reference only on wrapped continuation line | accepted — coder F-5 confirmed live |
| Nested bullets without references | exempt from rule 4 — load-bearing: the real CHANGELOG.md has such bullets (lines 905–912), so `testTheRealChangelogPassesWithNoArguments` indirectly pins this exemption |
| Impossible date `2026-13-45` | accepted (shape-only validation) — F-R4 |
| Fake reference inside inline code `` `(#123)` `` | accepted — F-R3 |
| `## [9.9.9] - …` / `### Added` inside a fenced code block | accepted; fence content became phantom structure — F-R2 |
| CRLF file | handled; line numbers reported correctly |

Exit codes verified live: 0 valid, 1 violations, 2 usage (missing root,
missing/unreadable file, unknown option); `-h` exits 0. Multiple `--root=`
options: last wins (same as pick-issue — consistent, not a finding).

### Cross-checks requested by the task

- CI Lint job and pre-push hook reach the new check via `composer lint`:
  **confirmed** (see DEC-008 above). The coder's claim checks out.
- Other tests pinning composer.json scripts or CHANGELOG structure: **none
  found beyond ChangelogStructureTest** (sweep listed above) — consistent, no
  assertions went stale.
- The `main()` resolution (coder F-1): making `main(): void` exit internally
  does **not** avoid the collision hazard — three scripts now declare a global
  `main()`, and the new script additionally re-declares `parseArgs()` and
  `printUsage()` which `bin/pick-issue.php` already declares. What actually
  silenced PHPStan was dropping `exit(...)` around the bare `main(parseArgs(...))`
  call so no result is consumed from a foreign declaration. The redeclare-fatal
  hazard is unchanged and latent (today every invocation is its own process;
  tests use `proc_open`). Recorded as F-R5, extending coder F-1.
- CHANGELOG entry format: matches neighbouring entries exactly (top-level
  bullet, two-space wrapped continuations, trailing
  `([#654](https://…))` link), placed under `[Unreleased]` → `### Added`. ✓
- Task-input correction: the hand-off said "13 sandboxed fixture scenarios fail
  with line-numbered messages". Actual composition of the 14 methods: 7
  violation fixtures, 4 success fixtures, 2 usage-error fixtures, 1 real-file
  pass. Not a code defect; noted so future rounds describe the suite accurately.

## Findings

Full machine-readable list in [findings-review.md](findings-review.md). All
statuses OPEN (round 1).

- **F-R1 (medium)** — tests/ChangelogStructureTest.php:57–325 — fixture gaps:
  the equality boundary of the descending-order rule
  (`version_compare(...) >= 0`, bin/check-changelog.php:135), the
  "no headings at all" rule (:91–93), the "no released version headings" rule
  (:147–149), the silent skip of unknown subheadings (:156), and
  continuation-line reference acceptance (:180) are all unpinned. E.g. relaxing
  `>= 0` to `> 0` passes all 14 current tests because the out-of-order fixture
  only exercises strict ascent. Would-be catcher: the subprocess test class
  itself (three small fixtures + one duplicate-version fixture).
- **F-R2 (low)** — bin/check-changelog.php:217,239,249 — fenced code blocks are
  not skipped, so `## [`/`### ` lines inside ``` ``` ``` become phantom
  structure: a fenced example heading counts as a released heading and splits
  version blocks (which can both create false violations and hide real
  duplicates). Latent — the real file has no fences. Catcher: fixture test;
  fence-awareness in the line scanners.
- **F-R3 (low)** — bin/check-changelog.php:180 — the reference regex accepts
  non-references: `` `(#123)` `` in inline code or an anchor link `[x]` + `(#123)`
  satisfies rule 4 (probed). Extends coder F-5 with concrete false-positive
  shapes. Catcher: stricter regex (require the `[#n]` link form or
  end-of-entry position) + fixture.
- **F-R4 (nit)** — bin/check-changelog.php:121 — dates validated by shape only
  (`2026-13-45` passes) and never checked for monotonicity against the version
  order. Inherited from the pre-refactor test. Catcher: `checkdate()`-based
  fixture.
- **F-R5 (low)** — bin/check-changelog.php:307,324,366 vs
  bin/pick-issue.php:345,361,447 and bin/kb-lint.php:642 — global symbol
  collisions wider than coder F-1 states (`main` ×3, plus `parseArgs` and
  `printUsage` ×2). Latent fatal if two bin scripts are ever included in one
  process; the void-main change addressed only the PHPStan symptom. Catcher:
  none today — a bin-script symbol-collision scan (a test or kb-lint rule over
  `^function `/`^const ` declarations in bin/*.php) would catch it.
- **F-R6 (nit)** — bin/check-changelog.php:98 — exact whitespace-sensitive
  match on `'## [Unreleased]'`: a trailing space produces the misleading pair
  "found 0" + "does not match ## [x.y.z] - YYYY-MM-DD" instead of one precise
  message. Catcher: fixture test; `rtrim()` before comparison.
- **F-R7 (low)** — docs/proof_of_work/0654-changelog-validation/code-decision-1.md:53–55
  — proof-of-work inaccuracy: claims kb-lint.php and pick-issue.php both use
  "void main() that exits internally". Actually kb-lint declares
  `main(): int` (with internal `exit(0)`; declared return never used) and
  pick-issue's void main returns normally on success. The actual PHPStan fix
  was the bare call, not the signature. Risk: a future reader "restores
  consistency" from a wrong premise. Catcher: review (this one).

## Candidate knowledge-base entries (proposals only — main session decides)

1. **Title:** bin/ scripts declare colliding global symbols — including two in
   one process is a fatal.
   **Tags:** `bin`, `lint`, `tests`.
   **Trigger:** including/requiring a `bin/` script into another PHP process,
   or adding global functions/constants to a new bin script.
   Every `bin/*.php` CLI declares plain global functions and constants, and the
   names repeat across scripts (`main` in kb-lint/pick-issue/check-changelog,
   `parseArgs`/`printUsage` in pick-issue and check-changelog). Each script
   alone is fine — PHP resolves calls per file and each runs in its own
   process — but `include`-ing any two of them into one process fatals with
   "Cannot redeclare function". PHPStan will not warn (it analyses files as if
   loaded alone); the symptom it *does* produce is cross-file signature
   resolution on same-named functions, e.g. `exit(main(...))` type-checked
   against another script's `void main()`. Convention going forward: keep
   bootstrap/helper names unique per script (or namespace them), never include
   one bin script from another, and drive them from tests via `proc_open`
   subprocesses only.

2. **Title:** Subprocess-driver tests for a CLI validator must pin boundary
   semantics, not just representative violations.
   **Tags:** `tests`, `bin`, `lint`.
   **Trigger:** writing or reviewing a PHPUnit driver for a linter script (the
   KbLintScriptTest pattern).
   A fixture suite where every rule has one "representative bad" case can still
   let regressions through: boundaries and negative space stay unpinned. In
   #654's ChangelogStructureTest the descending-order fixture used strictly
   ascending versions, so flipping the script's `>= 0` to `> 0` (equal versions
   no longer rejected) passed the whole suite; likewise nothing pinned the
   empty-file rules, the "no released headings" rule, or the deliberate silence
   toward unknown `### …` subheadings. When driving a validator as a
   subprocess, add one fixture per boundary (equality case, empty input,
   unknown-token tolerance) and one per documented false-positive shape, or the
   subprocess pattern merely re-locates the drift the validator exists to stop.

3. **Title:** Line-based Markdown validators must decide whether fenced code
   blocks are prose.
   **Tags:** `markdown`, `bin`, `lint`.
   **Trigger:** parsing Markdown structure by line regexes (headings, bullets)
   in repository tooling.
   Regex-per-line scanners treat `## [x.y.z] - date` or `### Fixed` inside a
   a triple-backtick fence as real structure: in #654's check-changelog a fenced
   example heading counted as a released version heading and split version
   blocks, which can manufacture violations or hide real ones depending on
   placement. Any tool that classifies Markdown lines must either track fence
   state (``` / ~~~ toggles, respecting indentation) or document that fenced
   content is off-limits in the audited file. The real CHANGELOG.md currently
   has no fences, so the hazard is latent — which is exactly why it needs a
   recorded decision before someone embeds a code sample in the changelog.
