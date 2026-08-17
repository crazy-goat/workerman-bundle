# findings-review.md — issue #681 (merge duplicate allowlist bullets)

One entry per finding. Round 1.

---

## F-1 | docs/security.md:225 | traceability placeholder — surviving bullet inspected, no issue | nit | open

The surviving "Prefer the allowlist over the denylist" bullet (post-edit line
225) is correct and complete: it retains the default-posture rationale and the
"Configure `$allowedExtensions`" instruction that the deleted bullet
duplicated. No issue found — this row exists only so the surviving bullet is
on the review record. Status: **open** (not a real finding; will be marked
"not real" if a future round revisits).

No real findings were raised in round 1.

---

## Disposition (main session, after round 1)

- **F-1 | docs/security.md:225 | nit** — **not a real finding.** The surviving
  bullet was inspected by the review: it retains the default-posture rationale
  and the `` `$allowedExtensions` `` instruction in full, so the deletion of
  the verbatim-duplicate bullet loses nothing. Closed; nothing to fix.

---

## Step 14 outcome (main session)

Candidates verified by a read-only reviewer subagent on master:

- C1 (`security.md:224` split bullet) — **skip**: finding description
  factually inaccurate (two sentences, no double dash); bikeshedding.
- C2 (`.pi-subagents/artifacts` leak) — **skip**: hypothetical; `.gitignore`
  safeguard correct.
- C3 (`security.md:192` deprecation note placement) — **skip**: note explicit
  ("no effect … the setup shown above"); underlying issue #591 resolved.
- C4 (faq.md over 300-line budget — regression of #731) — **real, untracked**:
  filed as **#744** (`minor`, `code-quality`), on user confirmation.

No knowledge-base candidates were proposed by coder or review; nothing folded
into `docs/helpers/`.
