# Proof of Work (POW)

Every issue cycle described in [workflow.md](../workflow.md) leaves a
verifiable trail behind. This directory holds the **durable** part of it.

## Hybrid storage

A cycle produces two very different kinds of evidence, so they are stored in
two different places:

| Kind | What it is | Where it lives | Why there |
| --- | --- | --- | --- |
| **Narrative** | context, plan, coder report, review rounds, CI triage, audit | **PR comments** | Eight LLM essays per issue would drown the repository. GitHub assigns `created_at` server-side, so the ordering of the rounds is not forgeable — unlike a commit date. |
| **Durable** | `manifest.json`, `findings.md`, `escalation.md` | **this directory** | Machine facts and decisions outlive the PR, are diffable, and are reviewable. Two to three small files per cycle. |

The narrative is never authored by the orchestrator: `bin/pow.php --round`
takes the harness artifact
`.pi-subagents/artifacts/<runId>_<agent>_0_output.md` **verbatim**, injects
front matter derived from the neighbouring `_meta.json`, publishes it, and
records the comment id plus the sha256 of the exact published body in the
manifest. A round with no matching `run_id` on disk is invalid, a `run_id` is
published at most once, and a role's rounds never go backwards.

The comment is created with a single `gh api --method POST`, and the body the
API returns is hashed and compared with the bytes that were sent: if GitHub
ever normalised the content (the classic risk is `\n` → `\r\n`), the round
fails loudly at publication time instead of breaking every comment-chain check
later, in CI, on someone else's PR. One request also means a failure can never
orphan an already-published comment.

## Layout

```
docs/proof_of_work/
  README.md                  this file
  current/                   scratch buffer of the cycle in progress (gitignored)
    .gitkeep
    manifest.json
    findings.md
    escalation.md            only when the round cap forced an oracle verdict
  <NNNN>-<slug>/             the finished cycle, NNNN = zero-padded issue number
    manifest.json
    findings.md
    escalation.md
  .abandoned/<ts>/           abandoned cycles (gitignored, never deleted)
```

`current/` is a **local scratch buffer**: its contents are gitignored, so a
half-written cycle can never leak into a PR. `bin/pow.php --finish` moves the
three files into `<NNNN>-<slug>/`, which is the only committed form. Starting
a new cycle over a non-empty `current/` archives it to `.abandoned/<ts>/`
instead of deleting it — losing evidence is never the default.

`--finish` never overwrites a `<NNNN>-<slug>/` that already exists either. It
writes into it only when the incoming `findings.md` **starts with** the
recorded one (the append-only invariant still holds, so the cycle merely
continued); otherwise the recorded directory is moved to
`.abandoned/<ts>-<NNNN>-<slug>/` first and the move is announced. A second
`--start`/`--finish` for the same issue can therefore never silently replace a
previous ledger or leave a stale `escalation.md` beside a `verdict: CLEAN`
manifest.

The whole directory carries `export-ignore` in `.gitattributes`, so it is not
part of the distributed package.

## The two files

### `manifest.json`

Machine facts, no prose: `pow_version`, `issue`, `slug`, `branch`, `profile`,
`round_cap`, `created_at`, `rounds[]` (`n`, `role`, `agent`, `run_id`,
`comment_id`, `comment_sha256`, `prev`, `created_at`), `commits[]`,
`files_changed[]`, `lint_exit`, `test_exit`, `coverage`,
`findings{total,round1,escaped,open}`, `gates_added[]`, `aborted[]`,
`verdict`.

`pow_version` (currently `1`) is the schema version and the first key of the
file: consumers pin one shape instead of guessing, and evolving the manifest
later is a non-event. `bin/pow.php` refuses to read a manifest whose keys are
missing, mistyped or written for another version — a manifest it cannot
understand is never reported as usable.

`findings.escaped` — findings first seen in round 2 or later — is the metric
that drives the gate loop: a defect that escaped round 1 means a check was
missing, not that a reviewer was unlucky.

> **`profile` is orchestrator-influenced — re-derive it, do not trust it.**
> `--start` takes an explicit `--profile` (only refusing `light` on a branch
> whose prefix mandates `full`) and can only consult the issue's `process`
> label when `gh` is reachable — with `POW_NO_GH=1` the lookup is skipped and
> merely warned about. So `manifest.profile`, and the `round_cap` derived from
> it, are an input the cycle chose for itself. Any gate checking a cycle must
> re-derive the expected profile from the branch prefix and the issue labels
> and compare it against the recorded one, rather than taking the manifest's
> word for how many rounds were allowed.

### `findings.md`

An **append-only** ledger:

```markdown
| ID | round | file:line | description | severity | status | resolution |
| --- | --- | --- | --- | --- | --- | --- |
```

A status change is a NEW row with the same ID; an ID's effective status is
that of its last row. Rows are never edited or deleted, so no finding can
silently disappear between rounds. Statuses are `open`, `fixed`, `gated` and
`wontfix`; a `wontfix` must cite `decisions.md#<anchor>` or `escalation.md`.

### `escalation.md`

Written only when the hard round cap (4 for `full`, 2 for `light`) was
reached and the `oracle` had to pick one binding verdict — `NARROW`, `REDO`,
`ACCEPT` or `HUMAN`. An `ACCEPT` must name every still-open finding
individually (whole-word: naming `F-10` does not justify `F-1`), or
`bin/pow.php --verdict=ACCEPT` refuses to record it.

Both rules — "a non-empty `escalation.md` for anything but `CLEAN`" and "every
open finding named under `ACCEPT`" — are re-checked at `--finish` against the
state actually being shipped. Recording the verdict first and then adding open
findings, or emptying `escalation.md` afterwards, does not get past the gate.

## Tooling

Everything here is written by [`bin/pow.php`](../../bin/README.md#powphp) —
do not hand-edit the manifest or the ledger.
