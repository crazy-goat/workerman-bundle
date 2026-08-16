# Process Notices — Rejected Alternatives

A registry of process alternatives that were considered and rejected. Each
entry exists so the same alternative is not silently re-litigated without
first checking whether its trigger has actually fired — and so it *can* be
re-litigated once it has, instead of being forgotten. A trigger is a condition
**measurable** from the repository itself, never a feeling.

> **N-01 to N-13 are history.** They were written while building the
> machine-checked proof of work of issue #686 — `bin/pow.php`,
> `bin/check-pow.php`, the manifest, the ledger, branch profiles, the retro
> loop — all of which was removed in phase 6 (see entry #3 in
> [process-changelog.md](process-changelog.md)). Their triggers mostly refer
> to tooling that no longer exists and cannot fire — **N-12 and N-13 are the
> exceptions:** N-12's trigger has since effectively fired and that notice is
> superseded (see N-12, #704); N-13's factual basis changed when branch
> protection on `master` arrived as the `restric-main` ruleset, and that
> notice is superseded too (see N-13, #738). They are kept because the
> reasoning is still worth reading before anyone proposes the same thing
> again, not because they are live policy.

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

**Trigger:** nothing today records a step-13.5 finding as a discrete,
countable fact — `manifest.json` has no such field, `bin/pow.php` has no
command to append a finding to a cycle whose manifest `--finish` already
moved out of `current/` (step 13.5 runs *after* the merge, on an archived,
already-committed manifest), and `bin/pow-metrics.php` computes no such
counter. Building that recording path — a new field on an already-finished,
git-tracked manifest, written by a step that runs after the cycle it
describes has closed — is disproportionate for a rejected-alternative
notice, so the trigger uses a proxy that is already measurable without it:
**10 consecutive `full`-profile cycles** (`bin/pow-metrics.php --since=10
--min-cycles=3`) produce **no new entry** in `docs/process-changelog.md`.
Step 16 adds an entry for every `automation`/`workflow`/`knowledge` retro
outcome; only `noop` adds none — so a 10-cycle stretch with no new entry
*is* a 10-retro `noop` streak, visible from the changelog and
`bin/pow-metrics.php` together, without instrumenting step 13.5 itself.
**And** those cycles' round counts are evenly spread (not clustered at the
cap) — evidence the audit is cost without signal regardless of round count,
not evidence that short cycles specifically are safe to skip. If the audit
is ever revisited on its own findings rather than this proxy, giving it a
real recording path is the prerequisite follow-up work, not something to
retrofit into this notice.

## N-03 — Hard pre-push block

**Proposed:** make the pre-push hook fail (not just warn) `bin/check-pow.php`
on every push, not only on `^(fix|feat|refactor|perf|process)/issue-<N>` branches.

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

**Trigger:** `bin/pow-metrics.php --json`'s
`aggregate.escape_rate_by_profile.light` exceeds
`aggregate.escape_rate_by_profile.full` by more than 10 percentage points
over ≥5 `light` cycles — evidence that `light` changes escape checks at a
materially higher rate and the gate step should be mandatory there too.
(`escape_rate_by_profile` segments the single global `escape_rate` by each
cycle's `profile` field precisely so this trigger is checkable directly,
instead of requiring a reader to segment the per-cycle rows by hand.)

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

**Trigger:** the original wording ("the human/LLM picks something other than
`bin/pick-issue.php`'s top-ranked candidate in more than half of the last 10
cycles") is **not reconstructible**: nothing records what the script
recommended at the moment an issue was actually picked, and its ranking
depends on live milestone/label/age/comment-count state that has since moved
on — rerunning it later answers a different question, not the historical
one. Recording that recommendation going forward (a `top_candidate` field
`bin/pow.php --start` writes when `gh` can answer, mirroring how
`powResolveProfile()` already reads issue labels non-fatally) was considered
and set aside here as more than a notice warrants; it remains the natural
follow-up if this alternative is ever revisited in earnest.

In the meantime the trigger is something checkable **today**, without any
new instrumentation: run `php bin/pick-issue.php --json` for the active
milestone and look at the `score` gap between the `#1` and `#2` candidates.
A gap of **5 points or less** — smaller than the swing of a single label
(priority alone spans 3–60 points) — means the ranking is a near-tie the
formula cannot meaningfully resolve. If that happens for **3 milestones in
a row**, the weights themselves — not any single override — are the thing
to revisit, which is a narrower and more honest question than one this
notice cannot actually answer from history.

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

**Rejected because (phase 1):** it was rejected at the time, but that
rejection is **superseded (phase 7, #704)** and is no longer live policy.
The phase-1 rationale: the draft PR is created immediately after
the branch (step 2.5), before implementation starts, specifically so round
comments have a home from round 1 and CI starts earlier. The PR is also
where `closingIssuesReferences` lives (`POW-01`'s anchor) and where CI
status, the diff and the review conversation already converge — splitting
the narrative across both the issue and the PR would mean checking two
threads to reconstruct one cycle.

Why it is no longer live: (1) "Round comments have a home from round 1"
— since PR #697 the proof of work is four Markdown files committed under
`docs/proof_of_work/`, which have a durable home in the repository
regardless of any PR, so the argument that motivated PR-before-
implementation is gone. (2) "CI starts earlier" — CI on an empty branch
validates nothing: in the #670 cycle the draft ran the full matrix (PHP
8.2–8.5 × Symfony 6.4–8.0) on the seed commit and the implementation push
3 minutes later cancelled it (`concurrency: cancel-in-progress`) — wasted
runs, not earlier detection. (3) The step's practical premise was false:
`gh pr create --draft` fails with "GraphQL: No commits between master and
`<branch>`" on a branch with no new commits, so the cycle needed a junk seed
commit that polluted history. What survives: `closingIssuesReferences`
comes from the body's `Closes #<NUMBER>` line at workflow step 9, and the
PR remains the convergence point (diff, CI status, review conversation)
once there *is* content — which is why the workflow now creates the PR
after implementation and local gates.

**Trigger:** this trigger has effectively fired: since #697 the cycle's
narrative has lived in the committed files under `docs/proof_of_work/`, not
in PR round comments, so the "PR exists from the start" assumption this
decision relied on no longer holds — and workflow step 2.5 was removed in
#704. If round comments ever return as the proof-of-work home, or a
workflow variant again delays PR creation past the first reviews, re-
evaluate this alternative — posting round comments on the issue instead
of the PR — with the current facts.

## N-13 — A maintainer-approval requirement on `POW-09`/`POW-10`

**Proposed:** require a maintainer approval on the pull request — submitted
after the newest protected-path commit for `POW-10`, on record at all for the
`POW-09` `no-pow` bypass — mirroring how a typical branch-protection rule
requires a second reviewer before a sensitive change merges.

**Rejected because (phase 5, #686), superseded in part (#738):** this repository has **one** collaborator
with write access (`gh api repos/crazy-goat/workerman-bundle/collaborators`) and, at the time,
`master` carried **no GitHub branch protection**
(`gh api repos/crazy-goat/workerman-bundle/branches/master/protection` →
404 "Branch not protected"). GitHub does not allow approving your own pull
request. An approval requirement here is therefore not merely strict, it is
**impossible to satisfy** — it would deadlock every protected-path change and
every `no-pow` bypass forever, for a solo maintainer operating an agent that
already holds the `gh` credentials needed to open the PR, push to it, and
request or dismiss reviews on it. No in-repository mechanism can force a
second human into the loop when there is no second human with access to be
in it.

What the gate still buys, honestly stated: **visibility, not prevention.** The
`process/` branch prefix (`POW-10`) and the `docs/process-changelog.md` record
(`POW-09`) make a protected-path change or a bypass show up unmissably in the
diff and in every CI log that runs against it — a maintainer reviewing their
own history, or anyone else who later gets write access, sees exactly what
changed and why without having to reconstruct it. What it does **not** do is
stop the maintainer (or an agent acting with their credentials) from making
the change; there is no gate design available at this collaborator count that
could do that gate's job better without external tooling (a second GitHub
account with independent review authority, a required-reviewers branch
protection rule naming someone who is not the author, or similar) than
without it. Confusing "requires an approval field to be filled in" with "is
prevented" is exactly what the impossible check used to do.

**Trigger:** this trigger has now effectively fired (#738) on its second
branch — branch protection on `master` exists since 2025-07 as the
**`restric-main` ruleset** (`gh api repos/crazy-goat/workerman-bundle/rulesets`;
the classic `branches/master/protection` endpoint still 404s, which is why the
notice's factual basis looked stable): every push must come through a pull
request with green required status checks. What changed: `docs/workflow.md`'s
"no branch protection" notes were stale — direct pushes are declined and the
PR + `ci` check is a real gate. What did **not** change: the ruleset requires
no *review* approval, and GitHub still disallows self-approval with one
collaborator, so the specific alternative (a maintainer-approval requirement)
remains impossible — the notice's "visibility, not prevention" conclusion
stands. Revisit if a second collaborator with write access appears
(`gh api repos/crazy-goat/workerman-bundle/collaborators`).
