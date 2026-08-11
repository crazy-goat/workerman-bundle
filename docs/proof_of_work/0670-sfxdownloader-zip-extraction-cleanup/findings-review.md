# Findings — review (issue #670)

## F-1 | src/Phar/SfxDownloader.php:322 | `writeStream()` finally block has a bare `unlink()` — same class as #670, pre-existing | medium | open round 1

The partial-artifact cleanup in `writeStream()`'s `finally` block does
`unlink($destination)` with no `@`, no return-value check, and no
`error_log()` warning on failure. If that `unlink()` fails, a **partial**
download stays on disk and the next `fetch()` treats it as complete
(`is_file` short-circuit). Same self-perpetuating-poison class as #670,
arguably worse because the leftover bytes are truncated, not just corrupt.

Pre-existing (not introduced by this diff); the coder noted it in
`findings-coder.md` but left it out of scope. Does not block this change.

**Status: deliberately not fixed — out of scope of #670 (issue explicitly
scopes to the zip-extraction catch in `fetch()`; `writeStream()` is a separate
path). Tracked as follow-up: offered as a new GitHub issue at step 14 after
verification. Milestone 0.26.0 is intentionally small.**

## F-2 | tests/Phar/SfxDownloaderTest.php:421 | `error_log` capture may include unrelated PHP warnings on some CI configs | nit | open round 1

The test uses `assertStringContainsString` (not equality) on the captured
`error_log` content, which is robust against extra log lines. No current
code path emits unsuppressed warnings during the test, so this is not a
correctness issue — just a note that the assertion strategy is substring-
based by design.

**Status: not a real finding — by design. Substring assertion
(`assertStringContainsString`) is deliberately robust against accumulating
unrelated log lines; `@unlink` suppresses its own warning and no current
code path emits unsuppressed errors during the test. No change made.**
