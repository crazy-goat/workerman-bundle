# Findings — Coder — Issue #684

## Biggest problem faced

The issue body described the helper as hardcoding `REQUEST_METHOD => 'GET'`
and only accepting a `$range` parameter, but the current branch already had
the `string $method = 'GET'` parameter (added in commit `9bbb1be` / PR #685
for #683). The actual remaining work was narrower than the issue implied:
only the two older HEAD tests (from PR #682 / #643) still bypassed the
helper. The HEAD BinaryFileResponse test (line ~389) already used it. This
required reading git blame to understand the current state before making the
change, rather than blindly applying the issue's suggested fix.

## Discovered bugs / places to improve

### 1. Unused import would have been left behind (self-caught)

- **File**: `tests/ContentLengthDesyncTest.php:13`
- **Issue**: After replacing the two `Request::create(...)` calls with the
  helper, the `use Symfony\Component\HttpFoundation\Request;` import becomes
  unused. PHPStan/lint would flag it.
- **Fix**: Removed the import as part of this change. (Done.)

### 2. FAQ-001 line budget warning (pre-existing, out of scope)

- **File**: `docs/helpers/faq.md`
- **Issue**: `composer lint` reports `docs/helpers/faq.md: 376 lines (index
  excluded) is over the 300-line budget — promote or drop entries`. This is
  a pre-existing condition unrelated to this issue.
- **Suggested fix**: The retro step should promote or drop FAQ entries to
  bring the file under the 300-line budget. Not actionable in this PR.

### 3. Helper signature uses positional `$range` before `$method` (minor)

- **File**: `tests/ContentLengthDesyncTest.php:422`
- **Issue**: `createSymfonyRequest(?string $range = null, string $method = 'GET')`
  places the less-commonly-needed `$range` first. All call sites already use
  named arguments, so this is not a real problem, but a future caller using
  positional args would need to pass `null` for range to set a method.
- **Suggested fix**: No action needed — named arguments make this a non-issue.
  Noted only for completeness.
