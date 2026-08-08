# Knowledge Base (docs/helpers/)

This directory is a persistent knowledge base maintained by subagents
(`worker` / `coder` while implementing, `review` while reviewing). Lessons
learned here carry over to future tasks, so the same mistakes are not made
twice and past decisions do not need to be re-derived.

## Files

- [faq.md](faq.md) — frequently asked questions, recurring pitfalls and
  their solutions (test daemon ports, pre-push hook, coverage gate, `gh`
  default limits, long-running worker gotchas)
- [decisions.md](decisions.md) — important project decisions with rationale
  (architecture choices, security policies, conventions) and a reference to
  the issue/PR/commit that introduced them

## Rules

1. **Read before you start.** Any subagent about to implement or review
   changes reads `docs/helpers/faq.md` and `docs/helpers/decisions.md` first
   and follows any guidance that applies to the task.
2. **Append after you finish.** After implementation or review, append
   short entries for anything non-obvious you learned: a pitfall you hit
   (and its solution) or a decision you made (and why).
3. **One topic per entry.** Keep entries short: the problem, the
   solution/decision, optionally an issue/PR/commit reference. Do not
   restate what is already documented in the README or `docs/`.
   `docs/troubleshooting.md` already covers long-running worker state
   pollution — link to it instead of duplicating it.
4. **In doubt, ask.** If unsure whether an entry belongs in the knowledge
   base (or where), ask the user before adding it.
5. **Commit entries as part of the change** they were learned from — do not
   leave the knowledge base behind in the working tree.
