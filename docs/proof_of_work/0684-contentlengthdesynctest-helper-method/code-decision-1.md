# Code Decision 1 — Issue #684: add `$method` param to `createSymfonyRequest` helper

## Approach

The `createSymfonyRequest()` helper in `tests/ContentLengthDesyncTest.php`
already had a `string $method = 'GET'` parameter — it was added in commit
`9bbb1be` (PR #685, closing #683) when the HEAD BinaryFileResponse test was
introduced. However, two earlier HEAD tests (added in PR #682, closing #643)
still bypassed the helper by calling
`Request::create('/', Request::METHOD_HEAD)` directly:

- `testHeadRequestEmitsAppContentLengthAndNoBody` (line ~263)
- `testHeadRequestWithoutAppContentLengthEmitsZero` (line ~287)

The change was minimal: replace both `Request::create('/', ...METHOD_HEAD)`
calls with `$this->createSymfonyRequest(method: 'HEAD')`, and remove the now-
unused `use Symfony\Component\HttpFoundation\Request;` import (line 13) to
keep PHPStan/lint clean.

## What was rejected

- **Reordering the helper signature to `(?string $method = 'GET', ?string $range = null)`**
  — would break the two existing named-argument call sites that use
  `range:` (`createSymfonyRequest(range: 'bytes=0-99')` at line 313 and
  `createSymfonyRequest()` at line 353). Not worth the churn; the current
  `(?string $range = null, string $method = 'GET')` order works fine with
  named arguments.
- **Making `$method` nullable (`?string $method = 'GET'`)** — the issue
  suggested `?string`, but there is no semantic reason to allow `null` as a
  method. The existing `string $method = 'GET'` (non-nullable) from #683 is
  stricter and better. Left it as-is.

## Uncertainties

None. The change is purely a test refactor with no behavioral impact. All 12
tests in the file pass, PHPStan and lint are clean.
