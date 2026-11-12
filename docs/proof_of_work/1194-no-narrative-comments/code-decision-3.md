# Code decision — round 3 (#1194)

Fixes for review round 2: R2-F1, R2-F2, R2-F5, R2-F6. R2-F4 (optional nit) is left as is.

## R2-F1: namespaced and aliased PHPBench annotations

- `TestAnnotationTags::regex()` now accepts an optional namespace before a PHPBench tag name:
  `@Revs`, `@Bench\Revs(1000)`, `@\PhpBench\Attributes\Revs(1)` and
  `@PhpBench\Benchmark\Metadata\Annotations\Iterations(3)` all match.
- PHPUnit tags stay unqualified: PHPUnit never reads `@Foo\requires`, so it does not match.
- `@var Bench\Revs $b` does not match, because the namespace must follow `@` directly.
- Tests: `NoTestDocAnnotationsTest::testTheScanRecognisesAnnotations`, the
  `test-annotations-are-left-for-the-attribute-migration` fixer case (now with
  `@Bench\Iterations(5)`), and the aliased and FQCN data sets of `TestAnnotationsLintTest`.

## R2-F2: `@usesDefaultClass` and lists kept in sync

- `usesDefaultClass` was added.
- `testThePhpunitListCoversEveryAnnotationPhpunitParses` reads the `case '…':` labels of
  `vendor/phpunit/phpunit/src/Metadata/Parser/AnnotationParser.php` and fails if any is missing
  from `TestAnnotationTags::PHPUNIT`.
  - `requires`, `testWith`, `dataProviderExternal` and `dependsExternal` are parsed elsewhere in
    PHPUnit, so the test adds them by name.
  - If a future PHPUnit has no annotation parser (PHPUnit 12 dropped annotations), the test skips:
    PHPUnit then ignores doc annotations itself.
- PHPBench exposes its annotations cheaply as one class per tag in
  `vendor/phpbench/phpbench/lib/Benchmark/Metadata/Annotations/`.
  `testThePhpbenchListCoversEveryPhpbenchAnnotationClass` checks that every non-abstract class
  name is in `TestAnnotationTags::PHPBENCH`. `Worker` stays in the list as a harmless extra.
- Both tests check that the list covers the source, not that they are equal. Extra entries cost
  nothing, and a missing one is the bug.

## R2-F5: the unparsable-type reason in `composer lint`

- `bin/lint.sh` now runs `php-cs-fixer fix -vv --dry-run --diff`. At `-vv`, php-cs-fixer prints
  the exception message (`CrazyGoat/no_narrative_comments: cannot parse the type of "…"`) under
  "Files that were not fixed due to errors".
- **Contract:** the shared lint contract (header link,
  `crazy-goat/.github/standard/lint.md`) fixes the name, check-only behaviour, running every step
  and the tool set. It says nothing about verbosity, so `-vv` stays within it.
- On a clean tree `-vv` adds only the per-file progress line. `--fix` still uses `-v`, since the
  check pass runs afterwards anyway and prints the reason.

## R2-F6: the annotation scan is a lint step (DEC-008)

- New `bin/check-test-annotations.php`, modelled on `check-exception-usage.php`: `--root=`,
  `--help`, and exit codes 0, 1 and 2.
  - It scans `src/`, `tests/`, `benchmarks/`, `bin/`, `e2e/`, `tools/` and `e2e/console`, skipping
    `vendor`, `var`, `build` and `node_modules`.
  - It walks the filesystem rather than `git ls-files`, so it works on an exported tree and in
    sandboxes.
- `bin/lint.sh` runs it as the `test-annotations` step. Documented in `bin/README.md` (section,
  `lint.sh` list) and in AGENTS.md.
- PHPUnit coverage in `tests/TestAnnotationsLintTest.php` (subprocess, `LintScriptTestCase`):
  - the real tree passes;
  - six annotation forms fail, with file, line and tag;
  - strings, `e2e/vendor` and unrelated tags are ignored;
  - usage errors.
- The PHPUnit-only repo scan in `tests/Tools/NoTestDocAnnotationsTest.php` was dropped as a
  duplicate. That file keeps the matcher unit tests, the list-sync tests and a pin that `lint.sh`
  runs the step.

## R2-F4 (not done)

`<?php /* x */ echo 1;` → `<?php  echo 1;` only happens when the rule runs alone. The project
config rewrites the open-tag line and the repo dry-run is clean, so I left it.

## Uncertainties

1. The PHPUnit sync test parses PHPUnit's source text. A refactor of `AnnotationParser` that
   drops the `case` labels trips the `> 20` sanity assertion with a message saying to update the
   test. It fails loudly, never silently.
2. `-vv` makes a failing php-cs-fixer step a bit noisier (the stack-free exception box). That is
   acceptable for a failure path.
