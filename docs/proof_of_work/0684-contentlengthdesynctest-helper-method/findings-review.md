# Findings — Review — Issue #684

| # | file:line | what is wrong | severity | what happened to it |
|---|-----------|---------------|----------|---------------------|
| 1 | `docs/helpers/faq.md` (whole file) | `composer lint` reports `faq.md: 376 lines (index excluded) is over the 300-line budget — promote or drop entries`. Pre-existing, unrelated to this PR's diff (no `docs/helpers/` files changed). | nit | **open** — out of scope for this PR; the retro step should promote or drop FAQ entries to bring the file under the 300-line budget. Coder already noted it in `findings-coder.md` finding 2. |
