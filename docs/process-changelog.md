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
