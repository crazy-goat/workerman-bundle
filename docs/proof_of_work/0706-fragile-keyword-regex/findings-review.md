# Findings — review (issue #706)

- tests/Process/ProcessDocsTest.php:121 | Header extraction calls
  `$this->read('docs/process-notices.md')` twice (once for content, once for
  the `strpos` argument) — needlessly re-reads the file and is easy to
  mis-edit. | low | **still present** (self-reported by coder in
  findings-coder.md, deferred to keep the diff minimal; one-line fix:
  read once into `$content`, use it for both the slice and `strpos`).
- tests/Process/ProcessDocsTest.php:121 | If `## N-01` ever disappears,
  `strpos` returns `false`, `(int) false === 0`, and the "header" silently
  becomes `''` — both old and new assertions then fail with a confusing
  "empty header" diagnosis instead of "marker missing". | low | **still
  present** (self-reported, deferred; fix: `assertNotFalse(strpos(...))` with
  a clear message before slicing). Plausible automated check: PHPStan with
  phpstan-strict-rules (unchecked `strpos` / int-cast of boolable).
- tests/Process/ProcessDocsTest.php:126 | Failure message says
  "N-01..N-13 describe a mechanism that was removed" without mentioning the
  N-12/N-13 exceptions the header itself calls out; slightly overstated on
  failure. | nit | **still present** (cosmetic; the inline comment added in
  this PR does mention the exceptions, which mitigates it for readers of the
  code).

## Round 2 resolution (fix commit)

- tests/Process/ProcessDocsTest.php:121 (double `read()`) — **fixed**: content
  is read once into `$content` and reused for the marker lookup and the slice.
- tests/Process/ProcessDocsTest.php:121 (silent `strpos` false) — **fixed**:
  `assertNotFalse($markerPosition, ...)` with a message naming the missing
  `## N-01` marker now runs before `substr`; the `(int)` cast remains only to
  satisfy PHPStan level 8 without the phpunit extension.
- tests/Process/ProcessDocsTest.php:126 (failure message omits exceptions) —
  **fixed**: message now reads "…a mechanism that was removed (N-12/N-13
  excepted as superseded)".

## Round 2 review (post-fix commit 799d7b4)

- tests/Process/ProcessDocsTest.php:121 (double `read()`) — **fixed** (round 2
  verified: `$content = $this->read(...)` read once, reused at lines 126/131).
- tests/Process/ProcessDocsTest.php:121 (silent `strpos` false) — **fixed**
  (round 2 verified: `assertNotFalse($markerPosition, ...)` at lines 127–130
  precedes `substr`; cast removed, Rector-clean, PHPStan level 8 passes).
- tests/Process/ProcessDocsTest.php:126 (failure message omits exceptions) —
  **fixed** (round 2 verified: message ends "(N-12/N-13 excepted as superseded)").
- New findings round 2: none. Test run: 10/10 pass, 89 assertions.
