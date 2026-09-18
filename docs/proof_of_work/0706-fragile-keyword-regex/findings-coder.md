# Findings — coder (issue #706)

- `tests/Process/ProcessDocsTest.php:121` — the header extraction calls
  `$this->read('docs/process-notices.md')` twice (once for the content, once
  for the `strpos` argument). Harmless (small file), but needlessly reads the
  file twice and is easy to mis-edit. Suggested fix: `$content = $this->read(...); $header = substr($content, 0, (int) strpos($content, '## N-01'));`. Left as-is to keep this diff minimal.
- `tests/Process/ProcessDocsTest.php:121` — if `## N-01` ever disappears from
  the file, `strpos` returns `false`, `(int) false === 0`, and the "header"
  becomes an empty string — the old regex and the new anchored assertion both
  fail with a confusing "empty header" diagnosis instead of "marker missing".
  Suggested fix: assert `strpos` is not `false` with a clear message before
  slicing.
- `docs/process-notices.md:14` — the header names N-12 and N-13 as exceptions
  ("N-12's trigger has since effectively fired... N-13's factual basis
  changed..."), while the test failure message still says "N-01..N-13 describe
  a mechanism that was removed" without mentioning the exceptions. Cosmetic
  only; not changed since the message is the test's purpose statement, not
  documentation.
- In passing: `testProcessNoticesSaysItsTriggersReferToRemovedTooling` is the
  only test in the class whose "extract" logic is inline; the class has
  helpers (`extractNoticeSection`, `extractChangelogEntry`) — a
  `extractHeader` helper would fit the local style if more header assertions
  appear later.
