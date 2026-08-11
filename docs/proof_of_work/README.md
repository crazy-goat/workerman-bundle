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
manifest. A round with no matching `run_id` on disk is invalid.

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

The whole directory carries `export-ignore` in `.gitattributes`, so it is not
part of the distributed package.

## The two files

### `manifest.json`

Machine facts, no prose: `issue`, `slug`, `branch`, `profile`, `round_cap`,
`created_at`, `rounds[]` (`n`, `role`, `agent`, `run_id`, `comment_id`,
`comment_sha256`, `prev`, `created_at`), `commits[]`, `files_changed[]`,
`lint_exit`, `test_exit`, `coverage`, `findings{total,round1,escaped,open}`,
`gates_added[]`, `aborted[]`, `verdict`.

`findings.escaped` — findings first seen in round 2 or later — is the metric
that drives the gate loop: a defect that escaped round 1 means a check was
missing, not that a reviewer was unlucky.

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
individually, or `bin/pow.php --verdict=ACCEPT` refuses to record it.

## Tooling

Everything here is written by [`bin/pow.php`](../../bin/README.md#powphp) —
do not hand-edit the manifest or the ledger.
