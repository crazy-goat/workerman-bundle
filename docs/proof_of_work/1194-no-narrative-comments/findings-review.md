# Findings — review (#1194)

| # | file:line | what is wrong | severity | status |
|---|---|---|---|---|
| R1-F1 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:299-302 | Removing a comment with no whitespace on either side glues operators (`$a-/* x */-$b` → `$a--$b`, `+/**/+` → `++`); php-cs-fixer's post-lint refuses the file, so `lint-fix` cannot fix it. Check: fixer test case + non-comment token-stream equality assertion | low | open (round 1) |
| R1-F2 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:207-209, rector.php | A forgotten PHPUnit/PHPBench doc annotation (`@requires`, `@group`, `@covers`, `@depends`, `@Revs`…) is silently deleted by `lint-fix` instead of converted (verified). Check: Rector `PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES` in rector.php + fixer leaves such docblocks intact + scan test over tests/ and benchmarks/ | medium | open (round 1) |
| R1-F3 | src/Scheduler/Trigger/JitterTrigger.php:7 (base) | `@author Kevin Bond` removed. The class is derived from symfony/scheduler (MIT); there is now no provenance or copyright notice anywhere. Needs a LICENSE/NOTICE third-party section (maintainer decision); also audit sibling triggers | medium | open (round 1) |
| R1-F4 | docs/helpers/decisions.md:302, :309 | DEC-018 and DEC-022 refer to docblocks deleted in 948ddab; FAQ-028/FAQ-035 still use annotation syntax. The merger edits the KB in this PR | low | open (round 1) |
| R1-F5 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:20 | Directive regex is unanchored: any narrative comment mentioning `phpcs:`/`@phpstan-ignore` survives whole | nit | open (round 1) |
| R1-F6 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:220-222 | When a typed tag's type does not parse, the tag group (including its description) is kept verbatim: a silent escape from the rule; no instance today | nit | open (round 1) |
| R1-F7 | tools/php-cs-fixer/NoNarrativeCommentsFixer.php:89-97 | A one-line docblock with two tags (`/** @param int $a @param int $b */`) loses the second tag | nit | open (round 1) |
| R1-F8 | tools/php-cs-fixer/autoload.php:8 | Phar path hard-codes `vendor/` (breaks with a custom vendor-dir); otherwise robust, no shadowing | nit | open (round 1) |
