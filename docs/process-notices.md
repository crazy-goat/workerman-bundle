# Process Notices — Rejected Alternatives

A registry of process alternatives that were considered and rejected while
building the self-improving workflow (issue #686) and its retro loop
(`docs/workflow.md`, step 15). Each entry exists so the same alternative is
not silently re-litigated in a future retro without first checking whether
its trigger has actually fired — and so it *can* be re-litigated once it has,
instead of being forgotten. A trigger is a condition **measurable** from
`bin/pow-metrics.php` output or the repository itself, never a feeling.

Format: **Proposed** — what was suggested. **Rejected because** — the
reasoning at the time. **Trigger** — the measurable condition that would
justify reopening it.

## N-01 — Auto-split instead of the `oracle`

**Proposed:** when a cycle hits the round cap, automatically apply the
`NARROW` verdict (split the issue, keep the converged part) instead of
running the `oracle` subagent to pick one of `NARROW`/`REDO`/`ACCEPT`/`HUMAN`.

**Rejected because:** the four verdicts are not interchangeable. `REDO` means
the *approach* is wrong, not just the scope — auto-splitting a doomed
approach into two doomed halves wastes two cycles instead of one. `HUMAN`
covers BC-surface and security-policy calls the agent must not make silently.
Collapsing all four into "always split" removes the one point in the loop
where a fresh context is required to look at *why* four rounds did not
converge.

**Trigger:** `bin/pow-metrics.php` shows the `oracle` picking `NARROW` for
**every** capped cycle across ≥5 consecutive capped cycles (i.e. `REDO`/
`ACCEPT`/`HUMAN` never fire in practice) — evidence that the extra verdicts
are dead weight and a cheaper fixed rule would do.

## N-02 — Auditor (step 13.5) only runs from 3+ rounds

**Proposed:** skip the step-13.5 audit for cycles that converged in 1–2
rounds, on the theory that a fast, clean cycle needs no extra scrutiny.

**Rejected because:** a round-count threshold is gameable by the very
orchestrator it is meant to check: an incentive to "finish by round 2" to
dodge the audit is exactly the kind of pressure the audit exists to catch.
The audit's fresh context is what makes it resistant to a convincing
narrative — a threshold based on the narrative's own round count defeats
that property before the audit ever runs. It is therefore **always on** in
the `full` profile, at a per-cycle cost of one subagent run.

**Trigger:** `bin/pow-metrics.php`'s aggregate shows **zero** step-13.5
findings across ≥10 consecutive `full`-profile cycles **and** those cycles'
round counts are evenly spread (not clustered at the cap) — evidence the
audit is cost without signal regardless of round count, not evidence that
short cycles specifically are safe to skip.

## N-03 — Hard pre-push block

**Proposed:** make the pre-push hook fail (not just warn) `bin/check-pow.php`
on every push, not only on `^(fix|feat|process)/issue-<N>` branches.

**Rejected because (phase 2):** Composer aborts an array script on the first
non-zero command, so a gate that can fail inside `composer lint` blocks every
push on every branch, including branches nobody is running a cycle on. A hook
that blocks every push is a hook people bypass with `git push --no-verify`,
which makes the whole gate fiction. `composer lint` therefore runs the gate in
`--advisory` mode (always exits 0) and the hook adds one blocking run guarded
by the issue-branch pattern; the hard gate is CI, which cannot be bypassed
with a flag.

**Trigger:** `git log` shows `--no-verify` noted in ≥3 commit messages (or
an equivalent self-reported bypass) within a rolling window of 20 pushes —
evidence the advisory/hook split is not actually preventing the workaround it
was designed to avoid, and CI is catching problems the hook should have.

## N-04 — A separate metrics file (`cycles.jsonl`)

**Proposed:** append one JSON line per finished cycle to a dedicated
`docs/proof_of_work/cycles.jsonl`, so `bin/pow-metrics.php` would not need to
re-walk and re-parse every manifest on each run.

**Rejected because:** a derived file is a second source of truth that can
drift from the manifests it summarizes — exactly the drift class that caused
real bugs between `bin/pow.php` and `bin/check-pow.php` in phase 2, which
phase 4 fixes by sharing `bin/pow-common.php` rather than adding a third
copy. `docs/proof_of_work/<NNNN>-<slug>/manifest.json` is already the
durable, git-tracked record; recomputing from it is cheap at the cycle
volumes this project produces.

**Trigger:** `bin/pow-metrics.php`'s own wall-clock time exceeds 5 seconds
against the full manifest history (measure with `time php bin/pow-metrics.php
>/dev/null`) — i.e. the recomputation cost has actually become
noticeable, not merely nonzero.

## N-05 — Multi-writer knowledge base

**Proposed:** let `coder`/`coder-high` and `review`/`review-critical` append
directly to `docs/helpers/faq.md` and `decisions.md` when they learn
something, instead of only proposing candidate entries for the retro to land.

**Rejected because (phase 3, from what actually happened on this issue):**
two writers produced duplicate entries, entries with no tags or front matter,
and a 269-line file that had to be read end to end because nothing indexed
it. `DEC-009` makes the retro step the single writer; every other subagent
proposes in its report and reads only the tag index plus matching entries.

**Trigger:** `bin/kb-lint.php`'s near-duplicate warning fires for the same
pair of entries across ≥3 consecutive retros despite the single-writer rule —
evidence that a single writer is not sufficient discipline on its own and the
process, not just the authorship rule, needs to change.

## N-06 — `light` profile skips the gate step entirely

**Proposed:** apply the rule-of-two gate-escalation step (4b/4c) to `full`
cycles only, on the theory that `docs`/`chore`/`ci`/`test`/`build` changes are
low-risk enough that a missed regression is cheap.

**Rejected because:** this is the current, explicit design (see the profile
table in `docs/workflow.md`), not an alternative under consideration — kept
here as a notice precisely because "low risk" is an assumption, not a
measurement, and the profile table hard-codes it.

**Trigger:** `bin/pow-metrics.php`'s aggregate escape rate for `light`-profile
cycles exceeds the `full`-profile escape rate by more than 10 percentage
points over ≥5 `light` cycles — evidence that `light` changes escape checks
at a materially higher rate and the gate step should be mandatory there too.

## N-07 — Commit signing as the tamper-evidence mechanism

**Proposed:** require GPG/SSH-signed commits on issue branches instead of (or
in addition to) the GitHub-comment hash chain (`POW-05`) as evidence the
proof of work was not backfilled.

**Rejected because (phase 2):** commit dates are trivially forgeable
(`GIT_AUTHOR_DATE`), so signing a commit proves authorship of content, not
the *time* it was produced — the property the round-comment chain needs
(`created_at` is server-assigned by GitHub and cannot be backdated). Signing
also requires every contributor to manage keys before they can push, which
`docs/workflow.md`'s current mechanism does not.

**Trigger:** a `POW-05` violation is recorded (a tampered or backfilled
comment actually gets past the chain check) on ≥1 real cycle — evidence the
comment-hash mechanism has a defeatable gap that signing would plausibly
close.

## N-08 — Deeper input-vs-diff audit at step 13.5

**Proposed:** have the step-13.5 audit re-read the original issue body and
diff every changed line against its stated acceptance criteria, instead of
only checking "does the evidence support that the flow ran as documented?"
against the proof of work, `git log --format=fuller` and the diff.

**Rejected because:** step 14 (verify candidate findings) and the review
rounds (step 4/6) already check implementation correctness against the
issue; step 13.5's job is specifically to catch a *process* inconsistency —
a claimed round that never happened, a resolved finding that reopened
silently — which is a narrower, cheaper, more mechanical check than
re-deriving acceptance criteria from prose. Widening its scope risks making
it redundant with steps that already exist, at higher token cost per cycle.

**Trigger:** a real defect ships that step 13.5 could only have caught by
also comparing the diff against the issue's acceptance criteria (i.e. neither
the review rounds nor step 14 caught it) on ≥2 separate cycles — evidence
the narrower scope is missing a real class of defect, not just a
hypothetical one.

## N-09 — Subagent triage instead of `bin/pick-issue.php`

**Proposed:** always delegate issue selection (step 1) to a triage subagent
that reads issue bodies and comments, rather than running the deterministic
`bin/pick-issue.php` scorer first.

**Rejected because:** `bin/pick-issue.php` costs a few tokens (titles,
labels, dates, comment counts only — bodies are never fetched) versus
thousands for a subagent that reads full issue threads, and it is
reproducible: the same milestone state always produces the same ranking.
The subagent path is kept as the fallback for open-ended triage
(`docs/workflow.md`, step 1), not removed.

**Trigger:** the human/LLM picks something other than `bin/pick-issue.php`'s
top-ranked candidate in more than half of the last 10 cycles — evidence the
scoring weights no longer reflect what actually gets prioritized, which
argues for retuning the score, not for discarding the deterministic pass.

## N-10 — An explicit proof-of-work size cap

**Proposed:** add a hard limit on `findings.md` row count or `manifest.json`
size, analogous to the knowledge base's 300-line-per-file budget
(`docs/helpers/README.md`), to keep a single cycle's proof of work bounded.

**Rejected because:** the round cap (4 rounds for `full`, 2 for `light`)
already bounds how large a single cycle's ledger can plausibly get — a
cycle that hit the cap without converging goes to the `oracle` for a verdict
(`NARROW`/`REDO`/`ACCEPT`/`HUMAN`) rather than accumulating findings
indefinitely. A second, independent size cap would duplicate a constraint
the round cap already enforces structurally.

**Trigger:** a single finished cycle's `findings.md` exceeds 50 rows (roughly
an order of magnitude over any cycle seen while building phases 1–4) despite
being within the round cap — evidence that the round cap alone does not
bound ledger size the way it was assumed to.

## N-11 — Full proof of work committed to the repo instead of PR comments

**Proposed:** commit the entire round narrative (coder reports, review
findings, CI triage, audit) into `docs/proof_of_work/` alongside
`manifest.json`, instead of keeping it in PR comments and committing only the
durable machine facts.

**Rejected because (phase 1):** eight LLM-generated essays per issue,
committed permanently, would dominate the repository's history and diff
noise for a project whose actual product is a Symfony bundle, not a
transcript archive. GitHub already assigns comments a server-side,
unforgeable `created_at`, which is exactly the property the round-ordering
proof needs (see N-07) — a property a committed file does not have on its
own without also solving commit-date forgeability.

**Trigger:** a PR's round comments become unrecoverable — deleted,
GitHub-side data loss, or a repository migration that does not carry
issue/PR comments — for even one cycle whose proof of work is later needed
(e.g. for step 17's kept/reverted review) — evidence the narrative needs a
durable home after all.

## N-12 — Round comments posted on the issue instead of the pull request

**Proposed:** post round comments (coder, review, audit) on the GitHub
*issue* rather than the pull request, since the issue is the stable
identifier referenced throughout the cycle.

**Rejected because (phase 1):** the draft PR is created immediately after
the branch (step 2.5), before implementation starts, specifically so round
comments have a home from round 1 and CI starts earlier. The PR is also
where `closingIssuesReferences` lives (`POW-01`'s anchor) and where CI
status, the diff and the review conversation already converge — splitting
the narrative across both the issue and the PR would mean checking two
threads to reconstruct one cycle.

**Trigger:** a cycle produces a pull request only after most of the round
comments would have already been needed (e.g. a workflow variant that
delays PR creation) on ≥2 cycles — evidence the "PR exists from the start"
assumption this decision relies on no longer holds.
