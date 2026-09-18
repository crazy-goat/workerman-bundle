# Review — round 2 (issue #706, branch test/issue-706-processdocstest-testprocessnoticessaysit)

Scope: `git diff origin/master...HEAD` (commits 0476d4b, 799d7b4); only production
change is tests/Process/ProcessDocsTest.php (docs/proof_of_work/* are workflow artifacts).

## Round 1 findings — disposition

1. tests/Process/ProcessDocsTest.php:121 | double `read()` — **fixed**: `$content`
   read once (line 121 of new file), reused for `strpos` (126) and `substr` (131).
2. tests/Process/ProcessDocsTest.php:121 | silent `strpos` false → empty header —
   **fixed**: `assertNotFalse($markerPosition, ...)` (lines 127–130) with a clear
   message runs before `substr($content, 0, $markerPosition)` (131). The `(int)`
   cast was removed after Rector's RecastingRemovalRector rejected it; with the
   phpunit extension PHPStan level 8 narrows `assertNotFalse` so the cast is
   unneeded — verified in the diff (no cast present).
3. tests/Process/ProcessDocsTest.php:126 | failure message omits N-12/N-13 —
   **fixed**: message now ends "(N-12/N-13 excepted as superseded)" (line 139).

## New findings

None. Verification performed:

- Anchor string `**N-01 to N-13 are history.**` exists verbatim in
  docs/process-notices.md:9 (bold sentence, exact match — no regex fragility).
- Type correctness: `strpos` result is `int|false`; `assertNotFalse` narrows to
  `int` for PHPStan level 8 (phpstan-phpunit); no cast, so no Rector conflict.
- PSR-12: trailing comma in multi-line call, final class, no issues.
- Edge cases: header extraction now fails loudly (assertNotFalse) instead of
  silently producing `''`. `substr($content, 0, $markerPosition)` is correct.
- The test name `testProcessNoticesSaysItsTriggersReferToRemovedTooling` is now a
  slight misnomer (assertion is about "are history", not "triggers") — nit-level
  at most, not blocking; renaming would churn blame for zero behavioral gain.
- No `.github/workflows/` change, so FAQ-032 sweep not required.
- Helpers: only tag-matched entry for docs is FAQ-019 (listen schemes) — not
  applicable to this diff. No violations.

## Test run

`vendor/bin/phpunit tests/Process/ProcessDocsTest.php`:
OK, 10 tests, 89 assertions (1 pre-existing runner warning about XDEBUG_MODE=coverage,
unrelated to this diff).

## Verdict

No open findings. All three round-1 findings are fixed and verified; no new
findings. Recommend merge.

## Candidate knowledge-base entries

- Title: "Anchor doc assertions on exact sentences, not keyword regexes"
  Tags: tests, docs. Trigger: writing assertions against documentation prose
  (ProcessDocsTest-style). Paragraph: keyword regexes like
  `/history|removed|no longer exist/i` break on synonym-preserving rewrites and
  keep passing after meaning drift. Anchor on an exact, stable sentence
  (ideally a bolded summary sentence the doc maintains deliberately), and guard
  any positional extraction (`strpos` + `substr`) with `assertNotFalse` and a
  message naming the marker, so a doc restructure fails loudly instead of
  yielding an empty slice.
- Title: "Rector RecastingRemovalRector vs (int) cast for PHPStan"
  Tags: phpstan, rector, tests. Trigger: adding `(int)` casts to satisfy PHPStan
  on `strpos`/similar boolable returns in tests. Paragraph: prefer
  `assertNotFalse` (with phpstan-phpunit extension) over `(int)` casts —
  RecastingRemovalRector removes such casts and PHPStan level 8 accepts the
  narrowed type, so the cast is both unnecessary and auto-reverted.
