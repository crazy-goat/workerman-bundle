# Review Round 1 — issue #726: reuse HeaderNameNormalizer cache key in extractHeaders()

**Branch:** `perf/issue-726-responseconverter-extractheaders-re-lowe`
**Commit:** b79e84c
**Diff base:** origin/master
**Reviewer:** review-critical (deep review, round 1)
**Date:** 2026-08-16

---

## 1. Earlier findings

`findings-review.md` does not exist yet (round 1). No prior review findings to revisit. Going straight to hunting.

---

## 2. Overall verdict

**Sound. No findings.**

The change is a minimal, correct performance optimization that eliminates one redundant `strtolower()` call per header per response on the hot path. The by-ref out-parameter pattern is well-established in PHP, introduces zero allocation overhead (COW semantics verified), and the key invariant (`strtolower(normalize($name)) === strtolower($name)`) holds by construction for all code paths. The `@param-out` annotation satisfies PHPStan level 8. The `?string` explicitly-nullable type avoids the PHP 8.4 implicit-nullable deprecation. Transport-header stripping semantics, the HEAD Content-Length exception, and Set-Cookie array handling are all behavior-identical. No BC break (class is `@internal`, added parameter has a default, no external callers of `normalize()`). The three new tests provide solid regression coverage including all four CORRECTIONS entries, cache-hit/miss out-param equality, and HEAD content-length survival on a warm cache.

---

## 3. New findings

None.

| ID | file:line | description | severity |
|----|-----------|-------------|----------|
| — | — | No findings | — |

### Areas checked in detail (all clean)

**A. Key invariant `strtolower(normalize($name)) === strtolower($name)`**
- `normalize()` assigns `$lower = strtolower($name)` before any branch, then returns `self::$cache[$lower] ?? self::cacheMiss($lower, $name)`.
- `cacheMiss()` returns either a CORRECTIONS entry or `implode('-', array_map(ucfirst(...), explode('-', $name)))`.
- `ucfirst` only uppercases the first character of each segment; `strtolower` reverses it. All four CORRECTIONS entries (`'etag' => 'ETag'`, `'content-md5' => 'Content-MD5'`, `'www-authenticate' => 'WWW-Authenticate'`, `'dnt' => 'DNT'`) lowercase back to their keys.
- Verified programmatically for all CORRECTIONS entries, mixed-case inputs, and the empty-string edge case — all OK.
- Guarded by `testNormalizedHeaderNameLowercasesBackToInput` data provider covering all 4 CORRECTIONS entries plus 9 additional headers.

**B. Hot path allocation-free (by-ref COW semantics)**
- `$lower = strtolower($name)` inside `normalize()` creates one new zend_string (same as before — it's the cache key).
- The by-ref assignment to the caller's `$lowerName` aliases the same zval; no copy is made (COW — strings are only duplicated on mutation, and the caller only reads `$lowerName` for `in_array()`).
- The old code created two strings per header: one inside `normalize()` (cache key, discarded on function return for cache hits) and one outside (`strtolower($normalizedName)`, used for comparison). The new code creates one and shares it.
- On a cache miss, the hash table copies the string for its own key storage — this happens identically in both old and new code and is not an additional cost from the by-ref mechanism.
- No array, tuple, or value object is created per header. Confirmed.

**C. TRANSPORT_HEADERS stripping semantics unchanged**
- `TRANSPORT_HEADERS` constant is untouched: `['content-length', 'accept-ranges', 'transfer-encoding']`.
- The strip condition `in_array($lowerName, self::TRANSPORT_HEADERS, true) && (!$isHead || $lowerName !== 'content-length')` is identical; only the source of `$lowerName` changed, and its value is the same by the invariant.
- HEAD Content-Length exception: preserved. Test `testHeadContentLengthExceptionSurvivesWarmNormalizerCache` confirms exactly one `Content-Length: 777` on the wire with a pre-warmed cache, and `Accept-Ranges` is still stripped.
- Set-Cookie array handling: `flattenHeaderValues()` still uses `strcasecmp($name, 'Set-Cookie')` with the normalized name (not the lowercased key) — unchanged by this diff.

**D. `@internal` contract and BC surface**
- `HeaderNameNormalizer` is `@internal`. Adding an optional parameter with a default (`?string &$lower = null`) is not a BC break even for external callers (old call sites `normalize($name)` still work). No callers outside `src/` exist — verified by grep; the only references in `StaticFilesMiddleware.php` and `StaticFilesRealPathCache.php` are comments citing the pattern, not actual calls.
- `ResponseConverter::normalizeHeaderName()` is `private` on a `final readonly class` — signature change is invisible externally.
- Public constants `HEADER_CACHE_MAX_SIZE`, `HEADER_NAME_MAX_BYTES` and test affordances `cache()`, `resetCache()` are all unchanged.

**E. `?string &$lower = null` parameter shape**
- `?string` is explicitly nullable — NOT an implicitly-nullable type. No PHP 8.4/8.5 deprecation. Verified on PHP 8.5.9 with `E_ALL`: no deprecation warnings.
- `@param-out string $lower` satisfies PHPStan's `parameterByRef.unusedType` rule (the function always assigns a non-null string). PHPStan level 8 passes clean on both changed files.
- When the argument is omitted (e.g., `HeaderNameNormalizer::normalize('content-length')` in the warm-cache test), PHP creates a local variable with the default `null`; the internal assignment modifies only the local. No error, no side effect. Verified.
- `$lower` is assigned as the first statement in `normalize()`, before any branch — including the `cacheMiss()` early return for implausibly long names. The out-param is always set before return on every path.

**F. Knowledge base compliance**
- DEC-006 (security policy): transport-owned header stripping is preserved — no loosening.
- DEC-013 (optimization gates on security parsers): not directly applicable (this is a name-normalization cache, not a security gate), but the optimization doesn't change any security-relevant behavior.
- DEC-014 (bounded static caches): the HeaderNameNormalizer cache (cap + plausibility skip + test affordance) is unchanged.
- FAQ-001 (HEAD + Content-Length): the HEAD exception is preserved and tested.
- The coder's code-decision-1.md does not cite any FAQ/DEC entries by ID. It references issue numbers (#726, #574, #579, #643, #683). This is acceptable — the relevant KB entries govern preserved behavior, not the optimization technique. No inaccuracies in the cited issue references.

**G. Proof-of-work file accuracy**
- `code-decision-1.md`: accurate. One cosmetic typo ("dead-end cleanuup" — double 'u') in a rejected-alternative bullet; not in source code.
- `findings-coder.md`: accurate. The out-of-scope observations (`flattenHeaderValues` allocation, `in_array` linear scan) are correct and appropriately deferred. The `ContentLengthDesyncTest.php` reflection assessment is verified — that test does not reference `normalizeHeaderName` or `HeaderNameNormalizer` at all.

---

## 4. Candidate knowledge-base entries

**None required.** The change is a small, self-contained optimization that doesn't introduce a new pattern or pitfall. The by-ref out-parameter technique is standard PHP. The bounded-cache pattern (DEC-014) already covers the `HeaderNameNormalizer` cache. No new recurring pitfall or project decision emerged.

---

## 5. Remaining risk areas

| Area | Status |
|------|--------|
| Key invariant for future CORRECTIONS entries | Covered by `testNormalizedHeaderNameLowercasesBackToInput` — any new CORRECTIONS entry whose value doesn't lowercase back to its key will fail this test. |
| Cache eviction interaction with out-param | Not a risk — `$lower` is assigned before cache lookup; eviction only affects the cached normalized value, not the key. |
| Multibyte header names | Pre-existing: `strtolower`/`ucfirst` are byte-oriented. The invariant holds for ASCII (all real HTTP header names). Not changed by this diff. |
| JIT/opcache interaction with by-ref | Not a risk — by-ref parameters are well-handled by PHP's JIT; the bottleneck was the redundant `strtolower()`, not parameter passing. |
| Coverage floor (80%) | Not regressed — the change only adds tests; the coverage floor in `composer.json`'s `coverage:check` is unchanged. Coverage driver unavailable in this environment, but the coder correctly notes this cannot regress from test-only additions. |

---

## 6. Commands run

| Command | Result |
|---------|--------|
| `git diff origin/master --stat` | 5 files, 254 insertions, 5 deletions |
| `git diff origin/master` (full) | Reviewed all changes |
| `vendor/bin/phpunit tests/ResponseConverterTest.php --filter=...` (3 new tests) | 15 tests, 21 assertions, OK |
| `vendor/bin/phpunit tests/ResponseConverterTest.php tests/ContentLengthDesyncTest.php tests/Strategy/DefaultResponseStrategyTest.php tests/HttpRequestHandlerTest.php` | 127 tests, 10275 assertions, OK |
| `vendor/bin/phpstan analyse src/Http/Response/HeaderNameNormalizer.php src/Http/Response/ResponseConverter.php --level=8` | No errors |
| PHP 8.5.9 deprecation check (`?string &$lower = null`) | No deprecation warnings |
| Invariant verification (PHP script) | All CORRECTIONS + ucfirst + edge cases OK |
| `grep -rn "HeaderNameNormalizer::normalize" src/ tests/` | Only ResponseConverter + tests |
