# Review 4 — #558 StaticFilesMiddleware realpath-cache eviction LRU test

**Reviewer:** review agent (round 4)
**Branch:** `perf/issue-558-staticfilesmiddleware-realpath-cache-use`
**Commit:** `e2b591d` (on top of `c7b6943` → `ae2b4a7` → `8848d8a`)
**Cumulative diff:** `git diff origin/master...HEAD` — 10 files, +1189/-36
**Date:** 2026-08-16

---

## 1. Verdict

**Clean** — no open code finding of any severity. All seven previously
recorded findings (FR-001..FR-005, N1, N2) remain fixed, and the N3 fix from
round 3 is verified against the source. One new nit (N4) in POW docs only,
same stale-line-ref class as FR-002/N2/N3; per the documented no-linter
decision (`process-changelog.md` entry #3, `proof_of_work/README.md`), it is
fixed by hand in this round rather than by tooling.

## 2. All-round findings disposition

| ID | Disposition | Evidence |
|----|-------------|----------|
| FR-001 | **Fixed** | `git status` clean; transient stash-pop artifact, never in this PR. |
| FR-002 | **Fixed** | Current-state docs cite `:314-330`; `grep -n 'private function cacheStore'` → `314:`. |
| FR-003 | **Fixed** | `code-decision-1.md` says `tests/data/public`, matching `setUp()`. |
| FR-004 | **Fixed** | CHANGELOG wording distinguishes O(1) eviction from hash-insert growth. |
| FR-005 | **Fixed** | `StaticFilesRealPathCache` per DEC-014; `resetCache()` in `setUp()` (`:24`); reflection gone. |
| N1 | **Fixed** | `CACHE_KEY_MAX_BYTES = 512` (`:67`), guard (`:258-259`), pure `probeRealPath()` (`:285-307`), regression test (`:454`). |
| N2 | **Fixed** | `findings-coder.md` no longer cites `229-236`. |
| N3 | **Fixed** | `293-307` → `314-330` in code-decision-1.md, code-decision-2.md, findings-coder.md; historical records unchanged. |
| N4 | **Fixed (this round)** | `findings-coder.md` now cites `isFilePathBlocked` at `:163-181` and `isComponentBlocked` at `:182-227`. |

## 3. New findings

| ID | file:line | description | severity |
|----|-----------|-------------|----------|
| N4 | `docs/proof_of_work/0558-staticfiles-realpath-eviction/findings-coder.md:64` | Stale line ref `:135-148` for `isFilePathBlocked`/`isComponentBlocked` — actual `:163`/`:182`. Noted in review-3.md §6 but never formally tracked with an ID. Same class as FR-002/N2/N3; fixed by hand (no-linter decision). | nit |

## 4. Focus-check answers

- **N3 fix:** correct — current-state docs cite `:314-330`; historical
  records keep their round-appropriate numbers.
- **Deliberate no-linter note:** accurate — `proof_of_work/README.md:69`
  ("Write the files by hand. If they are wrong, that is what review is
  for.") and `process-changelog.md` entry #3 (deletion of `bin/pow.php` /
  `bin/check-pow.php`, ~4,000 + 3,300 lines) corroborate the claim.
- **Cumulative sweep:** no open code findings; 122/122 middleware tests;
  `array_shift` and `getRealPathCache` absent from `src/` `tests/`.
- **Record sanity:** review-3.md's `:459` ref for the regression test is off
  by 5 (actual `:454`) but is a historical record and correctly left as-is;
  the findings-review.md outcomes table matches the files.

## 5. Candidate knowledge-base entries

None new — the recurring stale-line-ref class is already documented in the
findings-review.md no-linter note.

## 6. Validated clean

`probeRealPath()` extraction behavior neutrality; plausibility-skip
fail-open (DEC-013); LRU-vs-FIFO test semantics; `@internal` contract;
CHANGELOG format and numbers; no BC break; no security regression.
