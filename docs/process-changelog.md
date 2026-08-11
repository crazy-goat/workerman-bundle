# Process Changelog

This file records changes to the **process** — `docs/workflow.md`,
`docs/helpers/`, `bin/`, the agent prompts — as opposed to
[CHANGELOG.md](../CHANGELOG.md), which records changes to the bundle itself.
Append an entry when the process changes; come back and fill in the outcome
once there is enough evidence to judge it.

## Format

One entry per process change, in the order they land:

- **Date** — ISO-8601
- **Issue** / **PR** — the tracking numbers
- **What** — the process change, one line
- **Why** — the problem it addresses
- **Success criterion** — a statement that can be checked **without
  interpretation** against the repository ("`tests/LintScopeTest.php` passes
  on Linux CI", not "works well")
- **Outcome** — `pending` | `kept` | `reverted`

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
- **Outcome:** **reverted** — the criterion failed on the very first cycle it
  was applied to. #662 (PR #689) produced a complete manifest with two
  recorded rounds and an intact comment chain, and `--strict` rejected it over
  a `coverage` field the local run had no PCOV to fill. The manifest, the
  ledger and the gate that read them are removed in entry #3; nothing that
  would satisfy this criterion still exists.

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

### #3 — The proof of work becomes four Markdown files, and nothing checks them

- **Date:** 2026-08-11
- **Issue:** #686 (phase 6)
- **PR:** #697
- **What:** deleted `bin/pow.php`, `bin/check-pow.php`, `bin/pow-common.php`
  and `bin/pow-metrics.php` (~4,600 lines) with their tests (~3,300 lines),
  both proof-of-work CI jobs, the `manifest.json` schema, the append-only
  `findings.md` ledger, the sha256 comment chain, branch profiles and round
  caps, the `no-pow` escape hatch, and workflow steps 4b, 4c, 13.5, 15, 16
  and 17. What remains is four kinds of file per cycle —
  `findings-coder.md`, `findings-review.md`, `code-decision-<x>.md`,
  `review-<x>.md` — written by the agents that do the work and read by a
  human. `docs/workflow.md` went from 1,481 lines to under 800.
- **Why:** the machinery cost more than the evidence it protected was worth.
  Three things settled it. It **blocked correct work**: PR #689 for #662 was
  green on lint, tests and benchmark and was failed by `--strict` over an
  unset `coverage` field (see entry #1's outcome). It was **not actually
  closing the hole it existed for**: `--round` accepted the truncated output
  of a SIGTERM-killed run because nothing read `exitCode` from the artifact
  (#696), so a review going badly could be killed and recorded as a completed
  round — the exact "silent re-roll" `POW-07` was built to prevent, and
  `POW-07` could never run in CI anyway because the artifact directory is
  gitignored. And it was **welded to one harness**: four code sites hardcoded
  `.pi-subagents/artifacts/`, and the one check that could not be ported —
  enumerating runs the orchestrator would rather not mention — was the only
  one carrying real weight.
- **What is lost, stated plainly:** nothing now detects a fabricated round, an
  edited finding or a manifest that lies about its exit codes. That was a real
  property and it is gone. The judgement is that a solo maintainer reading
  four short files during review catches the same things at a fraction of the
  cost, and that a gate which fails honest work while missing the dishonest
  case was buying less than it appeared to.
- **Success criterion:** over the next 5 cycles, no PR is blocked by
  proof-of-work bookkeeping while lint, tests and benchmark are green — and
  each merged cycle's directory contains at least `findings-review.md` and one
  `review-<x>.md`. Checkable from the CI history and `ls docs/proof_of_work/`.
- **Outcome:** pending

### #4 — Review files are committed after every round, including clean ones

- **Date:** 2026-08-11
- **Issue:** #667 (follow-up), PR #699
- **PR:** (this PR)
- **What:** `docs/workflow.md` step 6 now instructs the main session to commit
  `review-<x>.md` and the `findings-review.md` appends after EVERY review
  round — clean rounds included — because the read-only review subagent never
  commits and a clean round has no fix commit to sweep its files up.
- **Why:** cycle #667 converged in round 2 with no findings, so
  `review-2.md` and the round-2 status appends sat uncommitted in the working
  tree through lint, ready-for-review, CI and the squash merge (§698). They
  reached `master` only via a second docs PR (§699), which the `restric-main`
  ruleset made necessary: master rejects direct pushes. Uncommitted review
  files are not proof of work yet — they exist only until the context that
  holds them is compacted.
- **Success criterion:** for each merged cycle, every `review-<x>.md` written
  during its rounds exists in the merge commit — checkable with
  `git show <merge>:docs/proof_of_work/<NNNN>-<slug>/review-<x>.md` for all
  `<x>` recorded in `findings-review.md`. Reconciliation PRs like §699 stop
  appearing.
- **Outcome:** pending

### #5 — The PR is created after implementation and local gates, not as a draft before any code exists

- **Date:** 2026-08-11
- **Issue:** #704
- **PR:** #705
- **What:** removed workflow step 2.5 (a draft PR opened immediately after
  branch creation, before any code) and moved PR creation into step 9: the
  PR is now opened — ready, not draft — after implementation and after step
  7's linters/tests pass locally. The proof-of-work directory creation
  (`mkdir -p docs/proof_of_work/<NNNN>-<slug>`) moved to the end of step 2,
  since it does not depend on a PR.
- **Why:** both justifications for the draft-first step were stale. "Round
  comments have a home from round 1" died when the proof of work moved from
  PR comments to committed files under `docs/proof_of_work/` (entry #3, PR
  #697) — the files have a home whether or not a PR exists. "CI starts
  earlier" was waste: in the #670 cycle the draft ran the full matrix (PHP
  8.2–8.5 × Symfony 6.4–8.0) on the seed commit and the implementation push
  3 minutes later cancelled it (`concurrency: cancel-in-progress`). The
  step was also not practical as written: `gh pr create --draft` fails with
  "GraphQL: No commits between master and `<branch>`" on a branch with no new
  commits, so the cycle needed a junk seed commit that polluted history.
- **Success criterion:** `grep -nE "2\.5|--draft|gh pr ready"
  docs/workflow.md` finds nothing, and the only multi-line `gh pr create`
  code block in the file sits in step 9 (the Quick Reference keeps its
  usual one-line condensed echo of the step, as it does for every step) —
  checkable directly with grep, no interpretation needed.
- **Outcome:** pending
