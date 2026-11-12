# Review round 2 — #1194 (review-critical)

Diff: `git diff d50bb71..HEAD`, three commits:
- 57c4535: fixer, autoloader, `TestAnnotationTags`, tests, `rector.php`;
- af6c2f8: one-line rewording of review-1;
- 26be6fa: CONTRIBUTING and proof of work.

## 0. Trigger

`review-critical` still applies:
- the PR as a whole touches `src/Http`, security-relevant and process-supervision code, public
  interface docblocks, and more than 200 lines;
- the round-2 diff alone is +645/−30 and changes lint tooling: the custom fixer, the autoloader
  and `rector.php`.

## 1. Knowledge base

The tags are the same as round 1 (`lint`, `policy`, `tests`, `phpstan`, `phpbench`).
- **DEC-007 (coverage floor) and DEC-008 (`bin/lint.sh` as the single entry point):** respected.
  `bin/lint.sh` was not touched.
- **No gate was lowered:**
  - `rector.php` gains a set, `PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES`;
  - PHPStan stays at level 8 with no new ignore;
  - the new files under `tools/` are analysed.
- **FAQ-037 (8.3+ syntax in a `^8.2` project):** no 8.3+ syntax in `TestAnnotationTags.php`,
  `autoload.php` or the new tests. No typed constants; the spread in an array (`[...A, ...B]`) is
  PHP 7.4+.
- **F4 still applies.** DEC-018 and DEC-022 still point at deleted docblocks, and FAQ-028 and
  FAQ-035 still use annotation syntax.

## 2. Earlier findings

| # | status | evidence |
|---|---|---|
| R1-F1 | **fixed** (one cosmetic leftover, R2-F4) | `joinsSafely()` (`NoNarrativeCommentsFixer.php:317`) re-tokenises `left . right` and requires exactly two tokens back. It guards all three branches (`:295`, `:304`, `:312`). Sandbox results: `$a-/* x */-$b` → `$a- -$b`; `+/**/+` → `+ +`; `1/* x */. 5` → `1 . 5`; `$c/* x */?->d`, `$i/* x */<=>$j`, `$l/* x */-1` and `"a"./* x */"b"` join safely, and the output parses. Every fixer data set now also asserts that the non-comment token stream is unchanged, which gates the whole class of bug. |
| R1-F2 | **fixed** (gaps, see R2-F1 and R2-F2) | `rector.php:11` keeps the PHPUnit set. The fixer skips any docblock where `TestAnnotationTags::find()` matches (`:78`). `NoTestDocAnnotationsTest` scans every tracked `*.php` file plus `e2e/console` and fails on a leftover. Sandbox: `@requires`/`@group`/`@dataProvider`/`@Revs` docblocks are kept intact. |
| R1-F3 | **still present** | Maintainer decision, deferred. `JitterTrigger` still has no provenance or notice, and there is no `NOTICE` file or third-party section in `LICENSE`. |
| R1-F4 | **still present** | Merger's KB edit, deferred. `docs/helpers/decisions.md:302` and `:309` are unchanged. |
| R1-F5 | **fixed** (one consequence, see R2-F3) | The regex is anchored at `:20`. Test `directives-must-start-the-comment` covers it. |
| R1-F6 | **fixed** | The fixer throws at `:223`. With `-v` (which `bin/lint.sh` uses), php-cs-fixer prints "Files that were not fixed due to errors reported during fixing: …/f.php" and exits 64, so `composer lint` fails. The message itself ("cannot parse the type of "@return array<int the list"; … use the @phpstan-return form") appears only at `-vv` (R2-F5). `testUnparsableTypeFailsLoudly` covers dry-run and fix. |
| R1-F7 | **fixed** | One-liners are split before every `@tag`. `/** @param int $a x @param int $b y @return int z */` → `/** @param int $a @param int $b @return int */`. `testNoAllowedTagIsEverDropped` runs 20 tag forms through 3 layouts. |
| R1-F8 | **fixed** | `autoload.php` resolves the phar in this order: `InstalledVersions`, then `COMPOSER_VENDOR_DIR`, then `config.vendor-dir`, then `vendor`. It is lazy, resolves once (`static $pharSource`), and throws a `RuntimeException` naming the expected path. `PhpCsFixerAutoloadTest` covers resolution and failure, in a subprocess, so the phar classes never load into PHPUnit. |

## 3. New issues (round-2 diff)

### Whole-docblock skip: can narrative survive in `src/`?

- **Yes, with respect to the fixer.** Any docblock containing a listed tag is left whole, summary
  included, anywhere in the tree. Sandbox: `/** Narrative @test */` and a `src/`-style docblock
  with `@uses Foo` stay as written.
- **The gate catches it.** `NoTestDocAnnotationsTest` scans every tracked PHP file, `src/`
  included, so `composer test` fails.
- **But the gate is a PHPUnit test, not a `bin/lint.sh` step.** A contributor who runs only
  `composer lint` or `lint-fix` sees green. CI and the pre-push contract (lint *and* test) close
  this gap, so it is acceptable.
- Moving the scan into `bin/lint.sh` would follow DEC-008 ("a new repository-wide check is added to
  `bin/lint.sh`"), and the scan is safe at any point in the cycle. Recorded as R2-F6 (nit).
- **False positives fail closed:** a prose `@group` in a docblock fails the test instead of slipping
  through. Acceptable.

### Is the skip list complete?

- **PHPUnit 10.5.64:** I diffed every `case '…'` in `Metadata/Parser/AnnotationParser.php`
  against `TestAnnotationTags::PHPUNIT`.
  - **Missing: `usesDefaultClass`** (R2-F2).
  - `requires`, `testWith` and `dataProvider` are parsed in `Annotation/DocBlock.php` and are
    listed.
  - Extra names that are attribute names, not annotations (`coversClass`, `usesFunction`,
    `dependsExternal`, `ignoreDeprecations`, …), are harmless.
- **PHPBench 1.x:** all 19 annotation classes in `lib/Benchmark/Metadata/Annotations/` are listed.
  The extra `Worker` is harmless.
  - **Gap:** PHPBench's documented *aliased import* style, `@Bench\Revs(1000)` with
    `use PhpBench\Benchmark\Metadata\Annotations as Bench;`, does not match. The regex requires the
    tag name right after `@`.
  - Sandbox: that docblock is **deleted silently**, and `NoTestDocAnnotationsTest` does not see it
    either. This is the R1-F2 defect class through a side door (R2-F1).
  - The fully qualified `@PhpBench\Benchmark\Metadata\Annotations\Revs` form has the same gap.

### F1 re-tokenisation

- **The logic is sound.** The tokens that will actually touch are checked:
  - `beforePrev` with `next` when the previous whitespace is dropped;
  - `prev` with `afterNext` when the next whitespace is dropped;
  - `prev` with `next` when the comment is replaced.
- **Conservative failure:** tokens that cannot be re-tokenised in isolation (heredoc fragments,
  `?>`, the open tag) compare unequal, so a space is kept and the result is safe.
- **Cosmetic side effect:** `<?php /* x */ echo 1;` becomes `<?php  echo 1;`, with a double space.
  `T_OPEN_TAG` already contains its trailing space and `'<?php <?php echo'` does not round-trip.
  - With the full `.php-cs-fixer.dist.php` it does not survive: verified, the open-tag line is
    rewritten (`blank_line_after_opening_tag`/`declare_strict_types`) and the result is
    `<?php\n\ndeclare(strict_types=1);\necho 1;`. It only shows when the rule runs alone. Not
    present in the repo (R2-F4, nit).

### F5 consequence: directives a tool honours mid-comment are now removed

- `php-code-coverage` honours `@codeCoverageIgnore` **anywhere** in a doc comment
  (`IgnoredLinesFindingVisitor.php:109`, `str_contains`), and in line comments only as exact
  `// @codeCoverageIgnore*` (`ParsingFileAnalyser.php:220-235`).
- For `T_COMMENT` the anchored regex matches php-code-coverage exactly, which is fine. For doc
  comments the fixer keeps `@codeCoverageIgnore` as a bare tag, also fine.
- **What remains:** a `/* … @phpstan-ignore x */` written mid-sentence is now removed.
  - If PHPStan honoured it, `composer lint` fails at the PHPStan step right after `lint-fix`, so
    the loss is loud, not silent.
  - The tree is unchanged by the stricter regex, as `lint-fix` and `lint` confirm.
- No action needed; recorded as a non-finding.

### F6 visibility

- `composer lint` fails correctly. But at `-v` the output names only the file, not the reason, and
  gives no "re-run with -vv" hint in the version installed here.
- A contributor has to know to run `vendor/bin/php-cs-fixer fix -vv <file>` (R2-F5).
- CONTRIBUTING now documents the rule, which mitigates it.

### F8 autoloader

- **Resolution order is correct.** Inside php-cs-fixer, the phar's own prepended loader wins, so
  this loader never runs for `PhpCsFixer\` classes. Under PHPStan and Rector the project's
  `InstalledVersions` answers first.
- **Failure mode:** throwing from an autoloader turns any `class_exists('PhpCsFixer\…')` probe
  into an exception rather than `false`. Only `tools/` code and the configs reference
  `PhpCsFixer\` in this process, and a missing shim means a broken dev install anyway, so this is
  acceptable.
- **Untested branch:** the `InstalledVersions` branch is not exercised by
  `PhpCsFixerAutoloadTest`, which runs without the Composer runtime. It is exercised indirectly by
  every PHPStan and Rector run in `composer lint`.
- **Nit:** POSIX-only absolute-path detection (`str_starts_with($vendorDir, '/')`). The project
  requires `pcntl`/`posix`, so this is not a finding.

### New tests

- `NoNarrativeCommentsFixerTest` (+4 cases, `testNoAllowedTagIsEverDropped`,
  `testUnparsableTypeFailsLoudly`, token-stream equality on every case), `NoTestDocAnnotationsTest`
  and `PhpCsFixerAutoloadTest` all pass.
- `runFixer()` now runs at `-vv` so the F6 message can be asserted. That is fine.
- **Gap:** there is no test for the aliased PHPBench form (R2-F1) or `usesDefaultClass` (R2-F2).
  Once the regex is fixed, add both to `testTheScanRecognisesAnnotations`.

## 4. Lint and tests

- **`LINT_SKIP_AUDIT=1 composer lint`:** all checks passed (php-cs-fixer, PHPStan level 8, Rector
  with the new PHPUnit set, kb-lint, changelog, exception-usage, upgrade-exceptions, shellcheck,
  hadolint).
- **The full `composer test` at HEAD (26be6fa)** ran on PHP 8.5.10, macOS, with grpc loaded:
  - 2961 tests, 19818 assertions, 47 skipped, **1 failure**;
  - the failure is `RestartBackoffTest::testStopSignalEndsTheWaitEarly` ("array contains 9"),
    the known FAQ-007 grpc host failure, which was confirmed on base 43fc2cb in round 1;
  - the coder's af6c2f8 fix for `BinDirectoryTest::testNoTrackedDocMentionsTheRemovedProcessTooling`
    holds, since that test passes.

## 5. New findings

### R2-F1 — low: aliased or FQCN PHPBench annotations bypass the skip and the scan

- **Where:** `tools/php-cs-fixer/TestAnnotationTags.php:28`.
- **What happens:** `@Bench\Revs(1000)` (PHPBench's documented `use … Annotations as Bench` style)
  and `@PhpBench\Benchmark\Metadata\Annotations\Revs` are not matched. The fixer deletes the
  docblock, and `NoTestDocAnnotationsTest` cannot see what is already gone.
  - Not present in the repo, which uses short imports, so the severity is low.
- **Fix:** allow an optional namespace prefix:
  `(?:^|[\s*])@(?:[A-Za-z_][\w]*\\\\)*(` … `)(?![\w-])`.
  Add `@Bench\Revs(1)` to `testTheScanRecognisesAnnotations` and to the fixer's
  `test-annotations-are-left…` case.
- **Check:** that test case.

### R2-F2 — nit: `usesDefaultClass` missing from the PHPUnit list

- **Where:** `tools/php-cs-fixer/TestAnnotationTags.php:10-16`.
- **What happens:** PHPUnit 10.5's `AnnotationParser.php:149` parses `@usesDefaultClass`. It is
  absent from the list, so it would be silently stripped.
- **Fix:** add it.
- **Check:** a test that derives the list from `AnnotationParser.php`'s `case` labels, or at least
  asserts `usesDefaultClass`. A derived list would keep the list in sync on PHPUnit upgrades.

### R2-F3 — non-finding: anchored directives

See §3. It matches php-code-coverage's own rule for line comments, and any PHPStan directive it
removes fails `composer lint` loudly.

### R2-F4 — nit: double space after the open tag

- **Where:** `tools/php-cs-fixer/NoNarrativeCommentsFixer.php:304`.
- **What happens:** `<?php /* x */ echo 1;` becomes `<?php  echo 1;`, because `T_OPEN_TAG` does not
  round-trip in `joinsSafely()`. It is cosmetic, appears only when the rule runs alone (the full
  config rewrites the open-tag line), and is not in the repo.
- **Fix (optional):** treat a left token ending in whitespace as safe.

### R2-F5 — nit: the F6 reason is hidden at `-v`

- **Where:** `bin/lint.sh:59`.
- **What happens:** `composer lint` reports only the file name. The "cannot parse the type" reason
  needs `-vv`.
- **Fix (optional):** run the php-cs-fixer step with `-vv`, or mention
  `vendor/bin/php-cs-fixer fix -vv <file>` in CONTRIBUTING's new bullet.

### R2-F6 — nit: the annotation scan is a PHPUnit test, not a `bin/lint.sh` step

- **Where:** `tests/Tools/NoTestDocAnnotationsTest.php`.
- **What happens:** the fixer's whole-docblock skip means `composer lint` stays green while
  narrative and annotations survive. Only `composer test` fails.
- DEC-008 prefers repository-wide checks inside `bin/lint.sh`.
- **Fix (optional):** a `bin/check-test-annotations.php` step that reuses `TestAnnotationTags`.
  Keep the PHPUnit test for the `bin/` coverage convention.

## 6. Candidate KB entries (for the merger)

The round-1 candidates still stand. One addition:

- **FAQ — A tag list that mirrors a tool's annotations must track the tool's parser**
  - tags: `lint`, `tests`, `phpbench`
  - trigger: "upgrading PHPUnit or PHPBench, or editing `TestAnnotationTags`"
  - `TestAnnotationTags` is hand-maintained. PHPUnit's annotation names live in
    `Metadata/Parser/AnnotationParser.php` (`case` labels) and `Annotation/DocBlock.php`.
    PHPBench's live in `lib/Benchmark/Metadata/Annotations/`, and PHPBench also accepts aliased
    and FQCN forms.
  - Prefer a test that derives the list (R2-F2) over this entry.

## Verdict

**Approve once R2-F1 and R2-F2 are fixed.** Both are one-line regex or list changes plus test
cases, and both are the R1-F2 class of defect. R2-F4 to R2-F6 are optional nits. R1-F3 (licensing)
and R1-F4 (KB edits) remain open for the maintainer and the merger, as agreed.
