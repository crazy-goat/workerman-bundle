# Findings — review (#1194)

| # | file:line | what is wrong | severity | status |
|---|---|---|---|---|
| R1-F1 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:299-302 | Removing a comment with no whitespace on either side glues operators (`$a-/* x */-$b` → `$a--$b`, `+/**/+` → `++`); php-cs-fixer's post-lint refuses the file, so `lint-fix` cannot fix it. Check: fixer test case + non-comment token-stream equality assertion | low | fixed (round 2, 57c4535): comment becomes a space unless `token_get_all()` proves the neighbours join safely; token-stream equality asserted in every fixer case |
| R1-F2 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:207-209, rector.php | A forgotten PHPUnit/PHPBench doc annotation (`@requires`, `@group`, `@covers`, `@depends`, `@Revs`…) is silently deleted by `lint-fix` instead of converted (verified). Check: Rector `PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES` in rector.php + fixer leaves such docblocks intact + scan test over tests/ and benchmarks/ | medium | fixed (round 2, 57c4535): Rector PHPUnit set kept in rector.php; fixer leaves docblocks with PHPUnit/PHPBench annotations untouched; tests/Tools/NoTestDocAnnotationsTest.php fails on any leftover |
| R1-F3 | src/Scheduler/Trigger/JitterTrigger.php:7 (base) | `@author Kevin Bond` removed. The class is derived from symfony/scheduler (MIT); there is now no provenance or copyright notice anywhere. Needs a LICENSE/NOTICE third-party section (maintainer decision); also audit sibling triggers | medium | open (maintainer decision, not touched by coder) |
| R1-F4 | docs/helpers/decisions.md:302, :309 | DEC-018 and DEC-022 refer to docblocks deleted in 948ddab; FAQ-028/FAQ-035 still use annotation syntax. The merger edits the KB in this PR | low | open (merger edits docs/helpers, not touched by coder) |
| R1-F5 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:20 | Directive regex is unanchored: any narrative comment mentioning `phpcs:`/`@phpstan-ignore` survives whole | nit | fixed (round 2, 57c4535): directive must start the comment body; test case added |
| R1-F6 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:220-222 | When a typed tag's type does not parse, the tag group (including its description) is kept verbatim: a silent escape from the rule; no instance today | nit | fixed (round 2, 57c4535): unparsable type throws, lint fails; tested |
| R1-F7 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:89-97 | A one-line docblock with two tags (`/** @param int $a @param int $b */`) loses the second tag | nit | fixed (round 2, 57c4535): one-liners split before every tag; testNoAllowedTagIsEverDropped |
| R1-F8 | tools/php-cs-fixer/autoload.php:8 | Phar path hard-codes `vendor/` (breaks with a custom vendor-dir); otherwise robust, no shadowing | nit | fixed (round 2, 57c4535): InstalledVersions / COMPOSER_VENDOR_DIR / config.vendor-dir, clear error; tested |

## Round 2 (review-critical, HEAD 26be6fa)

Status of the round-1 findings after round 2 (verified, evidence in review-2.md §2):

| # | round-2 status |
|---|---|
| R1-F1 | fixed: verified with `joinsSafely()` and token-stream equality on every case; cosmetic leftover R2-F4 |
| R1-F2 | fixed: verified Rector set, whole-docblock skip and repo-wide scan test; gaps R2-F1 and R2-F2 |
| R1-F3 | still present: maintainer decision, deferred |
| R1-F4 | still present: merger's KB edit, deferred |
| R1-F5 | fixed: verified; consequence analysed (R2-F3, not a real finding) |
| R1-F6 | fixed: verified, `composer lint` exits non-zero; reason only visible at `-vv` (R2-F5) |
| R1-F7 | fixed: verified |
| R1-F8 | fixed: verified |

| # | file:line | what is wrong | severity | status |
|---|---|---|---|---|
| R2-F1 | tools/php-cs-fixer/TestAnnotationTags.php:28 | Aliased or FQCN PHPBench annotations (`@Bench\Revs(1000)`, `@PhpBench\…\Revs`) do not match, so the fixer deletes the docblock silently and the scan test cannot see it (verified in sandbox). Check: add `@Bench\Revs(1)` to `testTheScanRecognisesAnnotations` and the fixer case | low | fixed (round 3): namespaced/aliased/FQCN PHPBench tags match; tested in matcher, fixer case and lint script |
| R2-F2 | tools/php-cs-fixer/TestAnnotationTags.php:10-16 | `usesDefaultClass` (PHPUnit 10.5 `AnnotationParser.php:149`) missing, so it would be stripped silently. Check: test deriving the list from PHPUnit's parser `case` labels | nit | fixed (round 3): `usesDefaultClass` added; lists verified against PHPUnit `AnnotationParser` cases and PHPBench annotation classes |
| R2-F3 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:20 | Anchored directive regex removes a mid-sentence `@phpstan-ignore`: matches php-code-coverage's line-comment rule; any real loss fails PHPStan in `composer lint` loudly | — | not a real finding (round 2) |
| R2-F4 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:304 | `<?php /* x */ echo 1;` → `<?php  echo 1;` (double space; `T_OPEN_TAG` does not round-trip in `joinsSafely()`); only when the rule runs alone, the full config rewrites the open-tag line | nit | open (optional nit, not done) |
| R2-F5 | bin/lint.sh:59 | An F6 unparsable-type failure shows only the file name at `-v`; the reason needs `-vv` | nit | fixed (round 3): bin/lint.sh runs php-cs-fixer with -vv, reason printed |
| R2-F6 | tests/Tools/NoTestDocAnnotationsTest.php | The annotation scan is a PHPUnit test, not a `bin/lint.sh` step (DEC-008); `composer lint` stays green while a skipped docblock keeps narrative | nit | fixed (round 3): bin/check-test-annotations.php as the `test-annotations` lint step, bin/README.md, tests/TestAnnotationsLintTest.php |
