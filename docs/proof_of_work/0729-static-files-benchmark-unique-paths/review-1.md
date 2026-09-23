# Review round 1 — #729

## Prior findings

No prior unresolved findings; initial review round.

## Review

- `init()` prepares 20,000 distinct missing paths before timing begins.
- `benchThrashingUniquePaths()` advances one index on each invocation and wraps only after exhausting the set. PHPBench runs 1,000 revolutions, so a single subject invocation set traverses nearly the entire set, exceeding `StaticFilesMiddleware::CACHE_MAX_SIZE` (1,024) and exercising negative-cache eviction.
- The same middleware instance is used for every request in the subject; the existing `@BeforeMethods` lifecycle recreates it for each benchmark subject/iteration, avoiding prior-iteration cache carryover.
- The benchmark uses missing paths to avoid fixture I/O and assertions, while still exercising unique cache keys and path probes.

## Checks

- Focused phpbench run — passed: 1 subject, 1,000 revolutions, 5 iterations, no failures (local mode 5.046 μs; advisory only).
- `composer lint` — passed (existing knowledge-base line-budget warnings only).
- PHP syntax, changelog structure, and `git diff --check` — passed.

## Findings

None. No knowledge-base candidate is needed.
