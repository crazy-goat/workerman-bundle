# Findings — review round 1, issue #574

Status legend: open / accepted / rejected. All findings start open.

---

## F1 — CHANGELOG.md has no [Unreleased] entry for #574

- **File:** CHANGELOG.md (diff omits it entirely)
- **Severity:** medium
- **Status:** fixed
- **What's wrong:** Acceptance criterion "CHANGELOG.md receives an entry under [Unreleased]" is not met by this branch. The coder's note that it is "handled by the main session" is a process claim, not a change; if the branch merged as-is the criterion would silently fail.
- **Automated check that could catch it:** a PR-template checklist item, or a lint gate along the lines of `bin/kb-lint.php` — e.g. a `composer lint` step asserting that any branch touching `src/**` references its issue number in CHANGELOG's `[Unreleased]` section.

## F2 — ResponseConverterBench before/after numbers not evidenced

- **File:** benchmarks/ResponseConverterBench.php (untouched; PR description not yet available to review)
- **Severity:** low
- **Status:** fixed
- **What's wrong:** Acceptance criterion requires benchmark numbers in the PR description; nothing in the diff or proof-of-work records them. The hit path is structurally unchanged (coalesce lookup; miss logic moved off-path), so no regression is expected — but the criterion asks for measurement, not expectation.
- **Automated check that could catch it:** phpbench run in CI with a ref comparison (`--ref=master --report=env` style gate), which would make the numbers a check rather than a description obligation.

## F3 — Long-name test hardcodes the 128-byte plausibility limit

- **File:** tests/ResponseConverterTest.php:433 (`assertLessThanOrEqual(128, ...)`)
- **Severity:** low
- **Status:** fixed
- **What's wrong:** `HEADER_NAME_MAX_BYTES = 128` is private, while `HEADER_CACHE_MAX_SIZE` was made public for tests — asymmetric. If the constant is ever tuned, the test's foreach bound silently diverges from production behaviour. Fix: make the constant public (same test-affordance rationale as the cap constant) and reference it in the test. The stronger assertion in the same test (`assertArrayNotHasKey($longName, ...)`) is not affected.
- **Automated check that could catch it:** none standard; a house lint rule flagging magic numbers in tests that mirror private constants, or simply review. (Making it public lets PHPStan's unused-public-constant check keep it honest.)

## F4 — "Memory does not grow linearly" clause of the flood criterion is unasserted

- **File:** tests/ResponseConverterTest.php:366-388
- **Severity:** low
- **Status:** accepted
- **What's wrong (narrowly):** The acceptance criterion literally asks the flood test to also assert memory does not grow linearly; the test asserts only `count(cache) <= cap`. The coder's rationale in code-decision-1.md — a hard entry bound implies a hard memory bound (each entry ≈ 2 × strlen + hashtable overhead) — is mathematically sound, so this is a documented deviation rather than a defect. Either get the criterion amended or add a coarse `memory_get_usage()` delta assertion; the latter is flaky-prone, so amendment is preferable.
- **Automated check that could catch it:** none realistic (memory assertions are environment-noisy); this is exactly the kind of residual the knowledge base exists for — see candidate KB entry.

## F5 — Fully-qualified `HTTP_OK` despite an existing `use Response` import

- **File:** tests/ResponseConverterTest.php:374, 398, 412, 424, 452, 468 (`\Symfony\Component\HttpFoundation\Response::HTTP_OK`)
- **Severity:** nit
- **Status:** rejected
- **What's wrong:** `Response` is already imported (line 13); the FQCN constant fetch is noise and inconsistent with the rest of the file, which uses the short name.
- **Automated check that could catch it:** php-cs-fixer `fully_qualified_strict_types` (with `import_symbols` covering constants) — currently not enabled in `.php-cs-fixer.dist.php`, which is why the dry-run passed.

## F6 — Plausibility check measures the original name, cache key is the lowercased name

- **File:** src/Http/Response/HeaderNameNormalizer.php:75-79
- **Severity:** nit
- **Status:** fixed
- **What's wrong:** `strlen($name)` gates caching, but the slot is keyed by `strtolower($name)`. For multibyte UTF-8 input the byte length can differ after lowercasing, so a key marginally above/below the limit is possible. Header names are ASCII tokens per RFC 9110 and Symfony rejects invalid names upstream, so this is theoretical; if it ever mattered, gate on `strlen($lower)` instead — one-character change, no behavioural difference for real names.
- **Automated check that could catch it:** none; noted for completeness.

## F7 — Cache reset only in setUp, not tearDown

- **File:** tests/ResponseConverterTest.php:25
- **Severity:** nit
- **Status:** fixed
- **What's wrong:** `resetCache()` in `setUp()` isolates this class's tests from each other, but leaves the static cache populated for whatever test class runs afterwards. Harmless today (entries are bounded and always hold correct values), yet a future test asserting on cache state would become order-dependent. A `tearDown()` reset (or reset in both) closes the trap.
- **Automated check that could catch it:** PHPUnit test-order randomisation with a canary assertion on a fresh cache; not worth automating now.

---

## Round 1 dispositions (main session, step 5)

- **F1** fixed — [Unreleased] Fixed entry added to CHANGELOG.md on this branch (main session, step 5/8).
- **F2** fixed — ad-hoc micro-benchmark recorded (phpbench not installed locally): new hit path 263 ns/op; old path 421 ns/op measured through reflection (~160 ns of that is reflection overhead) — hit path is structurally the same static-lookup coalesce, no regression. Numbers go in the PR description too.
- **F3** fixed — HEADER_NAME_MAX_BYTES made public; test now references the constant.
- **F4** accepted deviation, not fixed — hard entry bound implies hard memory bound (each entry ≈ 2×strlen + hashtable overhead); memory_get_usage() deltas are environment-noisy. Deviation recorded here per the process.
- **F5** rejected — not a real finding: the FQCN style is used throughout the whole pre-existing file (19 other occurrences); changing only the new tests would create the very inconsistency the nit complains about.
- **F6** fixed — gate now measures strlen($lower), matching the cache key (one-token change, no behavioural difference for ASCII names).
- **F7** fixed — tearDown() added alongside setUp().

---

## Candidate knowledge-base entries (for the retro step to triage)

1. **Title:** Bounded static caches in long-lived workers need cap + plausibility skip + test affordance
   **Tags:** `memory`, `long-running`, `http`, `tests`
   **Trigger:** "adding or reviewing a process-lifetime static/FIFO cache keyed by data the bundle does not control"
   **Paragraph:** Issue #574 established the house pattern for static caches in worker-shared code: an explicit `*_MAX_SIZE` constant enforced on every insert via `unset($cache[array_key_first($cache)])` (not `array_shift()`, per #558), a plausibility skip so implausibly long keys never enter the cache (mirroring Workerman's `MAX_CACHE_STRING_LENGTH`), and public test-affordance accessors (`::cache()` / `::resetCache()`) so the bound is assertable without reflection on a method-local static. Caveat discovered en route: a `final readonly class` cannot own the mutable static — extract an `@internal` utility class rather than dropping `readonly`. Complements DEC-004 and DEC-005.

2. **Title:** Acceptance-criteria clauses that ask for memory-growth assertions should be phrased as bounds
   **Tags:** `tests`, `memory`, `policy`
   **Trigger:** "writing acceptance criteria for a memory-leak/caching issue"
   **Paragraph:** Issue #574's criterion "assert memory does not grow linearly" cannot be tested without flaky `memory_get_usage()` deltas, while its sibling clause "assert cache size stays at or below the cap" fully pins the property (a hard entry bound implies a hard memory bound). Future criteria for unbounded-growth issues should be written as countable bounds (entries, slots, distinct keys) so the test is deterministic; when a literal clause is untestable, the coder should surface the deviation in proof-of-work (as happened here) rather than silently skipping it.

---

## Round 2 statuses (review-critical, verified in code)

- **F1** fixed (verified) — [Unreleased] Fixed entry present in CHANGELOG.md; details (512 cap, 128-byte skip, eviction mechanism, corrections preserved, issue link) all match the code.
- **F2** fixed (verified as repo-verifiable) — benchmark numbers recorded at the round 1 dispositions above; PR-description half not checkable from the repo.
- **F3** fixed (verified) — HEADER_NAME_MAX_BYTES public (HeaderNameNormalizer.php:42) and referenced by the test's foreach bound; no hardcoded 128 left.
- **F4** accepted deviation, unchanged — still only entry-count asserted, per recorded rationale. No action.
- **F5** not a real finding (rejection upheld) — measured: 20 FQCN usages, 0 short-form in tests/ResponseConverterTest.php; file-wide style claim is true.
- **F6** fixed (verified) — gate is `strlen($lower)` (HeaderNameNormalizer.php:68), matching the cache key.
- **F7** fixed (verified) — tearDown(): void with resetCache() added (tests/ResponseConverterTest.php:29-32).

Tests: php vendor/bin/phpunit --filter ResponseConverterTest → OK, 29 tests, 73 assertions (1 xdebug env warning).

New round 2 finding:

- **N1** open — findings-review.md:65-147: the round 1 dispositions block is duplicated 6 times (append-loop artefact); collapse to one block. Severity: low (docs hygiene). Full detail in review-2.md.

- **N1** fixed — duplicate dispositions blocks collapsed to one (main session).
