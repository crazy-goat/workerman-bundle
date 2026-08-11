# Process Changelog

This file records changes to the **process** — `docs/workflow.md`,
`docs/helpers/`, `bin/`, `.pi/agents/` — as opposed to
[CHANGELOG.md](../CHANGELOG.md), which records changes to the bundle itself.
It is written in two places:

- **Step 16** (`docs/workflow.md`) appends an entry the moment a `knowledge`
  retro outcome is committed. An `automation`/`workflow` outcome instead
  becomes a GitHub issue labelled `process`; its entry here is added once that
  issue's own PR merges, following the same one-entry-per-process-change rule.
- **Step 17** comes back after 5 cycles, checks each entry's success
  criterion against `bin/pow-metrics.php` and the repository, and fills in the
  `Outcome`.

`bin/check-pow.php` also **reads** this file, for the `no-pow` escape hatch
(`POW-09`): a bypass is valid only when some line here names both `no-pow`
and the exact issue or PR number involved — matched per line, with a word
boundary (`#700` does not match `#7001`, and a line that merely mentions the
number elsewhere is not a record). See "Proof-of-work gate" in
[workflow.md](workflow.md).

## Format

One entry per process change, in the order they land:

- **Date** — ISO-8601
- **Issue** / **PR** — the tracking numbers
- **What** — the process change, one line
- **Why** — the problem it addresses
- **Success criterion** — a statement step 17 can check **without
  interpretation** against `bin/pow-metrics.php` output or the repository
  ("escape rate below 20% over the next 5 cycles", not "works well")
- **Outcome** — `pending` | `kept` | `reverted`, filled in by step 17

## Entries

### #1 — Cycle zero: the proof-of-work format is exempt from its own gate

- **Date:** 2026-08-11
- **Issue:** #686
- **PR:** #687
- **What:** issue #686 (PR #687) introduces the proof-of-work format itself —
  `docs/proof_of_work/`, the `findings.md` ledger, `bin/pow.php`,
  `bin/check-pow.php`, the project-scoped agents in `.pi/agents/`, and the
  retro loop this changelog belongs to.
- **Why exempt:** `bin/check-pow.php`'s `POW-02` requires
  `docs/proof_of_work/0686-.../manifest.json` for the PR that closes #686.
  That manifest cannot exist: the manifest schema, the recorder that writes it
  and the gate that reads it are precisely what this PR is introducing, across
  four commits (one per phase). Requiring a proof of work in a format that
  does not exist yet at the start of the PR that creates it is not a
  completeness gap to route around quietly — it is the one case the `no-pow`
  escape hatch exists for, documented here rather than bypassed in silence.
- **Bypass:** `no-pow` label approved for PR #687 (closes #686).
- **Success criterion:** every issue opened after #686 merges produces a
  `docs/proof_of_work/<NNNN>-<slug>/manifest.json` that
  `bin/check-pow.php --strict` accepts on the first attempt — i.e. this is the
  last cycle-zero exemption the proof-of-work format itself ever needs.
- **Outcome:** pending

### #2 — Bootstrap: mining review history for the first gates

- **Date:** 2026-08-11
- **Issue:** #686
- **PR:** #687 (phase 4)
- **What:** before writing any bootstrap gate, mined `.pi-subagents/artifacts/`
  review history for finding classes that actually recurred, instead of
  guessing what to automate first. Corpus: 88
  `*_{review,review-critical,reviewer}_0_output.md` artifacts, 80 usable (8
  discarded: SIGTERM/empty), 59 carrying at least one concrete finding (21
  clean). Top recurring classes, by artifact count:

  | # | Class | Artifacts |
  | --- | --- | --- |
  | 1 | Docs↔code drift (prose / `info()` asserts behaviour the code lacks) | ~15 |
  | 2 | Test flakiness: wall-clock recency asserts, fixed sleeps, shared-daemon state | ~10 |
  | 3 | CHANGELOG accuracy / structure (dup headings, phantom APIs, missing `[Unreleased]`) | ~9 |
  | 4 | Tests that don't discriminate old vs. new behaviour; brittle source-greps | ~8 |
  | 5 | Untested error/fallback branch on a security-relevant path | ~6 |
  | 6 | Markdown structural defects (broken cross-dir links, wrong anchors, list-breaking fences) | ~6 |
  | 7 | Platform divergence — validated on macOS, differs on Linux CI | ~5 |
  | 8 | Unbounded process-lifetime state / static leakage between tests | ~5 |
  | 9 | Unsuppressed fs/syscall warnings, unchecked returns | ~4 |
  | 10 | Environment-gated pre-existing failures normalised as noise | ~4 |

  The **3 strongest gate candidates** — cheap to build, green or
  near-green on the current tree — were, in priority order:

  1. `tests/MarkdownLinkTest.php` (class 6, ~6 artifacts) — every internal
     markdown link and heading anchor resolves, case-sensitively, across the
     tracked `.md` files.
  2. `tests/ChangelogStructureTest.php` (class 3, ~9 artifacts; repeat
     offences #641, #255, #356) — `[Unreleased]` ordering, strictly
     descending released versions, no duplicate subheadings per version,
     every entry carries `(#N)`.
  3. `tests/LintScopeTest.php` — `bin/` is outside PHPStan, php-cs-fixer and
     Rector scope (3 artifacts, the cheapest gate of all: `bin/` already has
     7 real PHPStan errors and a live `Undefined array key 'number'` at
     `bin/pick-issue.php:517`).

- **Shipped:** #1 and #2 above, both in this PR.
- **Deferred:** #3, `tests/LintScopeTest.php`, is **not** shipped here. It
  fails by design on the current tree: `bin/` first needs adding to
  `phpstan.neon.dist`, `.php-cs-fixer.dist.php` and `rector.php` and its ~7
  PHPStan errors fixed (tracked separately as #635), and `tests/Fixtures`
  needs a `git mv` to resolve its case collision with `tests/fixtures`
  (passes on case-insensitive macOS/APFS, breaks on case-sensitive Linux
  CI — evidence in the mined artifacts). Landing the test before that work
  would either commit a permanently-red test or pull unrelated scope into
  this PR, so it is deferred to its own tracked issue (#688, `process`
  label, milestone 0.26.0) rather than silently dropped.
- **Why:** this bootstrap **is** the phase 4 acceptance criterion "a retro
  over the existing artifacts returns ≥3 gate candidates" — evidenced here
  by the 3 candidates above, rather than left to be inferred from a scratch
  file outside the repository. Picking gates from evidence that recurred
  across many independent review runs — not intuition — is the same rule
  the recurring step-15 retro loop enforces at step 15a (cite a metric or
  ≥2 occurrences, or classify `noop`).
- **Success criterion:** issue #688 is closed by a PR whose CI run shows
  `tests/LintScopeTest.php` passing (green on Linux CI specifically, since
  the case-collision half is invisible on a case-insensitive local
  checkout) — checkable directly from the issue tracker and CI, no
  interpretation needed.
- **Outcome:** pending
