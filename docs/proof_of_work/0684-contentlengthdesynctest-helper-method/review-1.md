# Review — Round 1 — Issue #684

**Branch:** `test/issue-684-contentlengthdesynctest-createsymfonyreq`
**Commit:** `0057c94` — `test(content-length): add $method param to createSymfonyRequest helper (closes #684)`
**Date:** 2026-08-22

## Helpers consulted (tag-index driven)

Tags matching the diff (`tests/`, `content-length`, `head`, `http`,
`response-strategy`):

- **FAQ-001** (http, head, content-length, response-strategy) — HEAD +
  app-set Content-Length desync hazard; the fix strips for non-HEAD,
  preserves the app value for HEAD. **Not violated** — the diff only
  touches test setup, not the production strip/preserve logic.
- **FAQ-002** (http, head, binary-file, streamed-response, response-strategy)
  — BinaryFileResponse HEAD strategy. **Not violated** — no strategy code
  changed.
- **FAQ-014** (tests, phpstan) — byte-oriented test helpers. **Not
  applicable** — no `chr()` helpers touched.
- **FAQ-031** (lint, bin, tests) — `bin/` in linter scope. **Not
  applicable** — no `bin/` files changed.
- **FAQ-032** (ci, tests, process) — workflow YAML pinning. **Not
  applicable** — no `.github/workflows/` files changed.

No documented decisions were violated by entry id.

## findings-review.md status

`findings-review.md` does **not exist** yet — this is round 1. No prior
review findings to triage.

## Verification

- `./vendor/bin/phpunit tests/ContentLengthDesyncTest.php` — **12 tests,
  42 assertions, OK** (1 PHPUnit warning: no coverage driver, expected on
  this host).
- `composer lint` — **clean**: php-cs-fixer 0/252 fixable, PHPStan
  [OK] No errors, Rector [OK], kb-lint OK (1 pre-existing FAQ line-budget
  warning, see finding 1 below), check-changelog OK.

## Diff analysis

The diff is a minimal, purely mechanical test refactor:

1. **Removes the now-unused `use Symfony\Component\HttpFoundation\Request;`
   import** (line 13). After replacing the two `Request::create(...)` calls
   with the helper, this import had no remaining references. Correctly
   removed — PHPStan/lint would have flagged it otherwise.

2. **Replaces two `Request::create('/', Request::METHOD_HEAD)` calls** (in
   `testHeadRequestEmitsAppContentLengthAndNoBody` at line ~262 and
   `testHeadRequestWithoutAppContentLengthEmitsZero` at line ~286) with
   `$this->createSymfonyRequest(method: 'HEAD')`. This makes all 5 call
   sites in the file use the same helper, consistent with the HEAD
   BinaryFileResponse test (line 388) that already used it since #683.

The helper (`createSymfonyRequest` at line 421) creates a `Request` with
only `REQUEST_METHOD` and `HTTP_HOST` server params, whereas
`Request::create()` populates a fuller server bag (`REQUEST_URI`,
`SERVER_PROTOCOL`, `QUERY_STRING`, etc.). This is a narrower setup, but
the tests pass identically — the Content-Length behavior under test does
not depend on those additional server params. The HEAD BinaryFileResponse
test already validated this helper shape in #683, so the two older tests
are now aligned with the established pattern.

## Overall verdict

**Clean.** The diff is a correct, minimal test refactor that achieves the
issue's goal (all HEAD tests route through `createSymfonyRequest`), removes
the dead import, and passes all tests and lint checks. No new findings
specific to this diff.

## Findings

1. **`docs/helpers/faq.md` — FAQ line-budget warning (pre-existing, out of
   scope)** | severity: **nit** | `composer lint` reports
   `docs/helpers/faq.md: 376 lines (index excluded) is over the 300-line
   budget — promote or drop entries`. This is a pre-existing condition
   unrelated to this PR (the diff does not touch `docs/helpers/`). The
   coder already noted it in `findings-coder.md` finding 2. No action
   needed in this PR; the retro step should address it.

## Automated-check notes

- The unused-import removal (finding 1 in `findings-coder.md`) would have
  been caught by **PHPStan** (`deadCatch` / unused rule) or
  **php-cs-fixer** (`no_unused_imports`). The coder self-caught it and
  removed it in the same commit — no separate check needed.
- No new automated check is warranted for this diff: it is a pure test
  refactor with no new defect class.

## Proposed candidate docs/helpers/ entries

None. The diff introduces no new pattern, pitfall, or decision worth
recording. The existing FAQ-001 already covers the HEAD + Content-Length
desync hazard that these tests characterise.
