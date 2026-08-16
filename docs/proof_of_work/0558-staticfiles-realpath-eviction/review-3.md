# Review 3 — #558 StaticFilesMiddleware realpath-cache eviction LRU test

**Reviewer:** review-critical agent (round 3)
**Branch:** `perf/issue-558-staticfilesmiddleware-realpath-cache-use`
**Commit:** `c7b6943` (on top of `ae2b4a7` → `8848d8a`)
**Cumulative diff:** `git diff origin/master...HEAD` — 10 files, +1050/-36
**Date:** 2026-08-16

---

## 1. Verdict

**Approve.** Round 3 implements the DEC-014 plausibility skip (N1 from round
2) with a clean, behavior-neutral extraction of the probe logic into
`probeRealPath()` and a new regression test that proves both fail-open serving
and no-cache for implausibly long paths. The `probeRealPath()` extraction is
verified behavior-neutral for all short-path cases (existing file, missing
file, symlink component, phar root, followSymlinks on/off) by line-by-line
diff against the old inline logic. The guard is fail-open per DEC-013 — it
changes only whether the probe result is cached, never which files are
servable. All protections (NUL/backslash rejection, realpath resolution,
root-prefix containment, extension/filename/allowlist blocking) run
independently of the cache and are unaffected. PHPStan level 8, PHP CS Fixer,
Rector, and kb-lint all pass clean. 122/122 StaticFilesMiddlewareTest tests
pass (241 assertions). One nit finding (stale line number in POW docs
re-introduced by the line shift) — not a blocker.

## 2. All-round findings disposition

| ID | Disposition | Evidence |
|----|-------------|----------|
| FR-001 | **Fixed** (still) | `git status` clean, no conflict markers. `composer lint` passes (PHP CS Fixer 0/247, PHPStan OK, Rector OK, kb-lint OK). 122/122 middleware tests pass. |
| FR-002 | **Fixed, then re-stale** (see N3) | `code-decision-1.md:16` and `findings-coder.md:9` cited `:293-307`, correct at round 2; the round-3 commit shifted `cacheStore()` to `:314` by adding `CACHE_KEY_MAX_BYTES` (6 lines) and `probeRealPath()` (~21 lines) above it. Same class as FR-002/N2 — stale `file:line` in POW docs. |
| FR-003 | **Fixed** (still) | `code-decision-1.md` says `tests/data/public`, matching `setUp()`'s `__DIR__ . '/data/public'`. |
| FR-004 | **Fixed** (still) | CHANGELOG wording now distinguishes O(1) eviction from hash-table-insert growth. |
| FR-005 | **Fixed** (still) | `StaticFilesRealPathCache` extracted per DEC-014; `setUp()` resets; reflection migrated; `getRealPathCache()` gone. |
| N1 | **Fixed** | `CACHE_KEY_MAX_BYTES = 512` (`:67`), guard in `resolveRealPath()` (`:258-259`), pure `probeRealPath()` (`:285-307`), regression test at `:459`. All three DEC-014 pillars present. |
| N2 | **Fixed** | `findings-coder.md` no longer cites `229-236` — line number removed, extraction stated instead. |
| N3 | **Fixed** (at review time, in this round) | stale `:293-307` refs re-introduced by round-3 line shift; corrected to `:314-330` in the current-state docs. |

## 3. New findings

| ID | file:line | description | severity |
|----|-----------|-------------|----------|
| N3 | `code-decision-1.md:16`, `code-decision-2.md:18`, `findings-coder.md:9` | Stale line number `:293-307` for `cacheStore()` — the method is at `:314` after the round-3 commit added `CACHE_KEY_MAX_BYTES` and `probeRealPath()` above it. Code claims still correct; only line references wrong. Fix direction: update the refs (done in this round) or drop line numbers entirely. Automated check: a doc-lint rule verifying `file:line` refs — deliberately not added, see the disposition note in `findings-review.md` (documented decision: no POW validation machinery, `process-changelog.md` entry #3). |

## 4. Focus-check answers

### 4.1 Behavior neutrality of `probeRealPath()` extraction (short-path path)

**Proven neutral by line-by-line diff** for all input shapes: phar root
(existing/missing file), non-phar with `followSymlinks=false` (symlink in
chain → `false` then negative `cacheStore`; no symlink → `realpath()`), and
`followSymlinks=true` (walk skipped). Structural changes verified
equivalent: `if/else` flattening, symlink early-return semantics (same `false`
stored and returned), identical component walk (`explode('/', ltrim($cacheKey,
'/'))`, skip `''`/`'.'`), identical `$now` timestamp and `$cacheIndex`
computation order.

### 4.2 The guard itself

- **Boundary fail-open:** yes — strict `strlen > 512`; only observable
  difference is caching, file-serving identical on both sides.
- **Key length vs guard:** the cache index appends the operator-controlled
  `rootRealPath` suffix (≈565-715 bytes worst case with a 512-byte path); the
  guard correctly bounds the client-controlled portion — the right threat
  model (attacker URLs, not operator config).
- **512 is sane:** Workerman's `MAX_CACHE_STRING_LENGTH` is 512;
  `HeaderNameNormalizer`'s 128 suits header names, request paths can
  legitimately be longer (content-hash asset paths, nested structures).
  Worst-case resident memory ≈730KB — acceptable per worker.

### 4.3 Can the plausibility skip bypass any protection?

**No.** NUL/`%00`/backslash rejection (`getPublicPathFile()`), realpath
resolution, root-prefix containment, symlink rejection (the walk inside
`probeRealPath()`), blocked extension/filename and allowlist checks all run
outside or after the cache. The skip changes only whether probe results are
cached — exactly the DEC-014 requirement and DEC-013 fail-open.

### 4.4 The new test

Proves fail-open serving (200 for a real file at a 566-byte relative path),
no positive cache entry (`assertArrayNotHasKey` on the key that would be
stored), and no negative cache entry (600-char missing path → 404 via next,
not cached). Cleanup in `finally` is bottom-up, preserves the root directory,
`@`-suppressed; segments 110 bytes each stay under the 255-byte filesystem
limit; the `$indexOf` key construction matches the source exactly.

### 4.5 Cumulative diff re-verified

LRU test (line 411, 6 assertions), `cacheStore` (`:314`, cap on every
insert), `StaticFilesRealPathCache` (@internal, `::all()` by-reference,
`cache()`/`resetCache()` affordances), `resetCache()` in `setUp()` (`:24`),
probe+guard (round 3), CHANGELOG (numbers consistent, precise wording,
Keep a Changelog format under `[Unreleased]` → `### Performance`), POW docs
present with N3 corrected.

### 4.6 Lint / static analysis / tests

PHP CS Fixer 0/247; PHPStan level 8 OK; Rector OK; kb-lint OK (1
pre-existing faq.md warning); PHPUnit StaticFilesMiddlewareTest 122/122
(241 assertions, 1.1s).

## 5. Candidate knowledge-base entries

1. **Plausibility skip in the realpath cache — probe-only path for long keys.**
   Tags: `static-files`, `memory`, `long-running`, `cache`. Trigger: reviewing
   or extending the plausibility skip, or auditing DEC-014 compliance. The
   guard `strlen($cacheKey) > CACHE_KEY_MAX_BYTES` (512, mirroring Workerman's
   `MAX_CACHE_STRING_LENGTH`) routes long request paths through pure
   `probeRealPath()` — never touching the cache — while all file-serving
   protections keep running outside the cache; fail-open per DEC-013. A
   regression test must assert both fail-open serving and no-cache for
   positive and negative cases.

2. (Round-2 candidate carried forward, now implemented — if promoted, describe
   the implemented pattern rather than the gap: `CACHE_KEY_MAX_BYTES = 512`,
   `probeRealPath()`, and the fail-open regression test are in place.)

## 6. Not fully verified (accepted)

- `findings-coder.md`'s old `:135-148` ref for `isFilePathBlocked` was stale
  since round 1 (now `:163`/`:182`) — noted, outside round-3 scope.
- The LRU test exercises negative entries only; positive entries go through
  the identical `cacheStore()` path (transitively covered).
