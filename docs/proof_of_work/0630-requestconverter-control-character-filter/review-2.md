# Review round 2 — #630 control-character filter: `preg_match` → `strcspn`

Branch: `perf/issue-630-requestconverter-control-character-filte`
Base: `master` · 3 commits (`716dc85` + `f5d0d64` + `3aa1a19`)
Reviewer: review-critical (round 2) · read-only

## Earlier findings (round 1)

`docs/proof_of_work/0630-requestconverter-control-character-filter/findings-review.md`
existed from round 1 with one entry:

- **F-1 (nit)** — duplicated 32-byte mask between production and benchmark with
  no link pinning them together. Round 1 marked it **FIXED**. Round 2 confirms:

  **FIXED — verified with evidence.**
  - `src/DTO/RequestConverter.php:29–31`: cross-reference comment added —
    *"Single source of truth: RequestConverterBench::CONTROL_CHAR_MASK must
    mirror this constant (pinned by the benchmark-mask equality test)."*
  - `benchmarks/RequestConverterBench.php:28–30`: cross-reference comment added —
    *"Must mirror RequestConverter::HEADER_VALUE_CONTROL_CHARS exactly — the
    micro-benchmark's old-vs-new comparison is only valid if the mask equals
    production (pinned by the benchmark-mask equality test)."*
  - `tests/RequestConverterTest.php:327`: new test
    `testBenchmarkControlCharMaskMatchesProduction()` reads both `private`
    constants via `\ReflectionClassConstant(…)->getValue()` and asserts
    `assertSame($production, $benchmark)`.
  - **Reflection test soundness** — verified at runtime:
    - Both constants are 32 bytes, hex-identical
      (`0001020304050607080a0b0c0d0e0f101112131415161718191a1b1c1d1e1f7f`).
    - `assertSame` uses strict `===` on strings (length + `memcmp`); divergent
      constants → test **FAILS** (confirmed by simulation).
    - Missing/deleted constant → `ReflectionException` → test **errors** (not
      pass) — confirmed by simulation.
    - Both classes load cleanly under dev autoloading:
      `CrazyGoat\WorkermanBundle\Benchmark\` → `benchmarks/` is registered in
      `composer.json` `autoload-dev` and present in
      `vendor/composer/autoload_psr4.php:61`.
  - PHPStan level 8, php-cs-fixer, and Rector dry-run are all clean on the
    three changed files.
  - The `3aa1a19` commit corrected the provenance hash in `findings-review.md`
    from `8bcdf81` to `f5d0d64` — the actual fix commit. Accurate.

  One minor doc-only observation (not a finding): `findings-review.md` and
  `review-1.md` both cite the test at `tests/RequestConverterTest.php:327`,
  but the actual line is **327** in the current file. This is a 6-line offset
  in a PoW documentation file and does not affect any automated check or the
  test's correctness.

## Overall verdict

**APPROVE.** The round-1 nit (F-1) is fully and correctly resolved. The fix
adds a bidirectional cross-reference comment on both constants and an
enforceable reflection-based equality test that cannot false-pass. No new
issues were introduced by the fix. All round-1 residual risks remain closed.

## New findings

None.

The fix is minimal (3 source lines of comments + 9 test lines), correct, and
introduces no new risk surface:

- The new test imports `RequestConverterBench` into the test suite, creating a
  `tests/` → `benchmarks/` dependency. This is safe: the benchmark namespace
  is in `autoload-dev`, the benchmark class has no side effects on load (just
  `private const` string literals and `private` properties initialized in
  `init()`), and PHPStan (which analyses both `tests` and `benchmarks` paths)
  is clean. The dependency direction is a leaf (test → both classes); the
  benchmark already depends on the production class, so no cycle is introduced.
- `ReflectionClassConstant` is available since PHP 7.1; the repo targets
  PHP 8.2+. PHPStan level 8 reports no issues with its usage.
- The test does not exercise any `src/` method bodies (it reads constants only),
  so it has no effect on the 80% coverage floor — which is unchanged at `80.0`
  in `composer.json` `coverage:check`.

## Round-1 residual risks — re-verified still closed

| Risk | Status | Evidence |
|------|--------|----------|
| Byte-identical behaviour, 256 values | **Closed** | Re-ran exhaustive sweep: 0 mismatches across all 256 single-byte values (middle/leading/trailing position), empty string, all bad×good two-byte combos, and long strings. Mask = 32 bytes, reject set = 32 bytes, accept set = 224 bytes. |
| `addcslashes` escaping unchanged | **Closed** | `src/DTO/RequestConverter.php:217` — `\addcslashes($stringValue, "\x00..\x1F\x7F")` unchanged. `addcslashes` supports `..` ranges (unlike `strcspn`), so the range syntax is correct here. Exception message byte-identical to before. |
| Filter runs on every header value | **Closed** | `src/DTO/RequestConverter.php:209` — `foreach ($workermanHeaders …)` loop, `strcspn` check at line 215 inside the loop body, no conditional bypass. The DEC-013 `rawHeadMayHaveDuplicates` gate (line 205–206) only controls raw re-parsing, not the control-char filter. |
| Benchmark verdict matches numbers | **Closed** | `benchFilterRegex` (line 94) uses the original regex `'/[\x00-\x08\x0A-\x1F\x7F]/'`; `benchFilterStrcspn` (line 110) uses `self::CONTROL_CHAR_MASK` with the same predicate as production. The new equality test pins the benchmark mask to the production mask, so the old-vs-new comparison remains valid. |
| Test cannot false-pass | **Closed** | `assertHeaderByteValidation` (line 337): rejected bytes that get dropped by Workerman's `parseHeaders()` would hit `$this->fail(…)` (line 358), not silently pass. Accepted bytes assert `assertSame($value, $server->get('HTTP_X_A'))` (line 354), which fails if the byte is altered. The new `testBenchmarkControlCharMaskMatchesProduction` cannot false-pass (verified by simulation above). |
| CHANGELOG entry valid | **Closed** | Under `## [Unreleased] > ### Performance` (line 38) with issue link `[#630]`, describes the change, states byte-identical behaviour, lists reject/accept sets, notes unchanged exception message, includes benchmark numbers. Keep a Changelog compliant. |
| Security policy (DEC-006, DEC-013) | **Closed** | Filter still rejects exactly `{0–8, 10–31, 127}`, accepts TAB + obs-text. No gate/fail-open surface introduced. Not loosened. |
| BC | **Closed** | New constant is `private`; no public interface changed; no parameter added to any published method. The new test is test-only. |
| Long-lived-worker hazards | **Closed** | Class constant (immutable), no per-request state, no cache, no closure, no timer. The new test adds no runtime state. |
| Coverage floor | **Closed** | `composer.json` `coverage:check` still `80.0`; unchanged. |

## Tooling verification

| Check | Command | Result |
|-------|---------|--------|
| PHPStan level 8 | `vendor/bin/phpstan analyse src/DTO/RequestConverter.php tests/RequestConverterTest.php benchmarks/RequestConverterBench.php` | **Passed** — No errors |
| php-cs-fixer | `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php …` (3 files) | **Passed** — 0 of 3 files need fixing |
| Rector dry-run | `vendor/bin/rector process --dry-run …` (3 files) | **Passed** — No changes |
| Reflection test logic | Standalone PHP script (`/tmp/verify_reflection.php`) | **Passed** — both constants 32 bytes, hex-identical, both classes autoload |
| Equivalence sweep | Standalone PHP script (`/tmp/verify_equivalence.php`) | **Passed** — 0 mismatches across 256 values + edge cases |
| False-pass resistance | Standalone PHP script (`/tmp/verify_falsepass.php`) | **Passed** — divergence → FAIL, missing constant → ReflectionException |

## Working tree and diff sanity

- `git diff master...HEAD --stat`: 9 files, 553 insertions, 4 deletions. Source
  changes: `src/DTO/RequestConverter.php` (13 lines, mostly comments), 
  `tests/RequestConverterTest.php` (17 lines), `benchmarks/RequestConverterBench.php`
  (58 lines, new file). Remaining: PoW docs + CHANGELOG + benchmark markdown.
- `git status --porcelain`: **clean** — no staged, modified, or untracked files.
  All changes are committed across the 3 commits.

## Candidate knowledge-base entries

None new for round 2. The round-1 candidate (strcspn/strpbrk mask is literal,
not a range) remains the only candidate; it is not weakened or invalidated by
the fix.
