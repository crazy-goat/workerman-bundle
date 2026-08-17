# review-1.md — issue #681 (merge duplicate allowlist bullets in docs/security.md)

Round 1. Branch: `docs/issue-681-security-md-use-the-allowlist-bullet-is`.
Base: `origin/master`. One commit: `a461bb2`.

## 1. Earlier findings

`docs/proof_of_work/0681-security-md-allowlist/findings-review.md` did not
exist before this round — round 1, no prior review findings to revisit.

## 2. Knowledge-base check

Loaded the tag index of `docs/helpers/faq.md` and `docs/helpers/decisions.md`
and read only entries whose tags match `docs/security.md` / documentation-type
changes:

- DEC-006 (`security,policy`) — "Security hardening from the #582–#586 review
  must stay intact." Lists the static-file allowlist as a hardening measure
  that must not be loosened without a documented reason. The edit does **not**
  loosen the allowlist guidance — it removes a verbatim-duplicate bullet while
  keeping the stronger, explanatory bullet intact. No violation.
- DEC-012 (`docs,markdown`) — "Raw angle-bracket placeholders in prose render
  as nothing on GitHub — backtick them." The surviving bullet uses backticked
  `` `$allowedExtensions` `` and `` `$rootDirectory` ``; no bare `<word>`
  tokens were introduced or left behind. No violation.
- FAQ-019 (`docs,listen-scheme`) and FAQ-027 (`security,php-strings`) — not
  relevant to this edit (listen schemes / control-char masks). Skipped after
  tag match confirmed no overlap.

No documented decision is violated by this change.

## 3. Verdict

**Approve.** The one-line deletion is the correct, minimal fix for the issue's
stated complaint (a verbatim-duplicate bullet). The coder's deviation from the
issue's literal suggested wording is justified and information-preserving: the
suggested wording would have dropped the "default posture / denylist is a last
line of defence, not a guarantee" rationale, which I confirmed exists nowhere
else in `docs/security.md` (the "Extension Allowlist" section at lines 152–192
covers only mechanics — which extensions are served and that the denylist takes
precedence — never the default-posture framing). Deleting that rationale would
weaken the doc for an issue whose complaint is solely the duplicated sentence.

## 4. New findings

| ID | file:line | description | severity |
|----|-----------|-------------|----------|
| F-1 | docs/security.md:225 | (none — listed for traceability) The surviving bullet is correct and complete; no issue found. | nit |

No real findings. The single "finding" row is a traceability placeholder
confirming the surviving bullet was inspected and is sound.

## 5. Cross-reference / collateral checks (all clean)

- **Grep for both bullet phrases** across the repo (excluding vendor,
  node_modules, `.pi-subagents`): the only hits are the two bullets themselves
  and the proof-of-work files quoting them. No test, no other doc, no KB entry
  references either string → deletion breaks nothing.
- **Tests referencing `docs/security.md`**: `tests/ComposerConfigTest.php`,
  `tests/RequestConverterTest.php`, `src/DTO/RequestConverter.php`,
  `src/Http/Request.php` all reference the *file* for unrelated sections
  (Composer Audit Advisory Suppression Policy, trust model, cookie decoding).
  None reference the "Security Considerations" bullet list → no broken anchors.
- **CHANGELOG.md**: has an `Unreleased > Fixed` section. A docs-only
  duplicate-bullet merge is documentation cleanup, not a code fix; no policy in
  `decisions.md` requires a CHANGELOG entry for docs-only changes. Not a
  finding.
- **UPGRADE.md**: covers breaking-change migrations only. A one-line doc
  deletion is not a breaking change. Not affected.
- **`bin/kb-lint.php` scope**: the linter sweeps `docs/helpers/` front matter
  and markdown conventions, not `docs/security.md` body prose. No gate applies.

## 6. Proof-of-work file review

- `code-decision-1.md` — accurate and honest. The line-number references
  (225/226) match the pre-edit tree; the "information-preserving" rationale is
  verifiable against the file; the cross-reference claim was independently
  confirmed by grep. The "unsure" section transparently flags the wording
  deviation for review. No inaccuracies.
- `findings-coder.md` — honest. The pre-existing `kb-lint.php` line-budget
  note (faq.md at 307 lines) is correctly marked out-of-scope (only the retro
  step may edit `docs/helpers/`). The weak-spot observations are cosmetic and
  outside this issue's scope, as stated.

## 7. Candidate knowledge-base entries

**none.** A one-line duplicate-bullet deletion in a doc does not encode a
reusable pitfall or decision. The existing DEC-006 already captures the
"don't loosen static-file hardening" policy that governs this area.

## 8. Gaps in validation / areas checked clean

- No `composer lint` / `composer test` re-run by review — the change is a
  single `.md` line deletion with no PHP/YAML/bin impact, and the coder's
  `composer lint` pass is credible for this diff shape. The full daemon-booting
  test suite is unnecessary (confirmed: no test references the edited strings).
- No staged files (`git status --porcelain` clean; `git diff --cached` empty).
- Working tree matches the single branch commit exactly.
