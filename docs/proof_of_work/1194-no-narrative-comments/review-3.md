# Review round 3 — #1194 (review-critical)

Diff: `git diff 59737a1..HEAD`, two commits:
- 442b0c4: `bin/check-test-annotations.php`, namespaced PHPBench tags, list-sync tests, `-vv`;
- 85cf648: proof of work.

## 0. Trigger

`review-critical` still applies:
- the PR as a whole touches `src/Http`, security and process-supervision code, and public
  interface docblocks, with more than 200 lines changed;
- round 3 adds a `bin/lint.sh` step and a new `bin/` script (+416/−42).

## 1. Knowledge base

- **DEC-008:** now satisfied for the annotation scan, which runs as the `test-annotations` step in
  `bin/lint.sh`. It also stays safe at any point in the cycle: it is file-local and needs no
  network or daemon.
- **FAQ-031 (`bin/` inside linter scope):** the new script is linted by PHPStan level 8,
  php-cs-fixer and Rector (`composer lint` is green). It is covered by `tests/TestAnnotationsLintTest.php`
  in the existing `LintScriptTestCase` style.
- **Gates:** none lowered. One raised: a new lint step, and php-cs-fixer runs at a higher verbosity.
- **F4 still applies.** DEC-018 and DEC-022 still point at deleted docblocks.

## 2. Earlier findings

| # | status | evidence |
|---|---|---|
| R1-F3 | **still present** | Maintainer decision, deferred. There is still no third-party notice for `JitterTrigger`. |
| R1-F4 | **still present** | Merger's KB edit, deferred. `docs/helpers/decisions.md:302` and `:309` are unchanged. |
| R2-F1 | **fixed** | `TestAnnotationTags.php:30` now accepts `(?:\\?Ident\\)*` before a PHPBench name. These match: `@Bench\Revs(1000)`, `@\PhpBench\Attributes\Revs(1)` and `@PhpBench\Benchmark\Metadata\Annotations\Iterations(3)`. Covered in `NoTestDocAnnotationsTest`, the fixer case and the `TestAnnotationsLintTest` data sets. |
| R2-F2 | **fixed** | `usesDefaultClass` was added, and `testThePhpunitListCoversEveryAnnotationPhpunitParses` derives the list from PHPUnit's `AnnotationParser` `case` labels. **Skip analysis:** the test skips only when `vendor/phpunit/phpunit/src/Metadata/Parser/AnnotationParser.php` is absent. `composer.json` pins `phpunit/phpunit: ^10.4`, so every CI leg resolves to 10.x (the lock has 10.5.64), and that file exists from 10.0 on. It cannot skip on CI. Run locally with `--display-skipped`: 14 tests, 0 skipped. The full suite skip count is unchanged at 47, as in round 2. The PHPBench test asserts and never skips; a moved directory fails it. |
| R2-F3 | not a real finding (round 2) | — |
| R2-F4 | **still present**, accepted | Not done, as stated. It only happens when the rule runs alone; the project config rewrites the open-tag line (verified in round 2). Accepted as a nit. |
| R2-F5 | **fixed** | `bin/lint.sh:59` now uses `-vv`. Verified with a temporary violating file: `composer lint`'s php-cs-fixer step prints `[UnexpectedValueException] CrazyGoat/no_narrative_comments: cannot parse the type of "@return array<int the list"; …` and exits 64. The file was removed afterwards. On a clean tree the whole lint log is 48 lines. **Contract:** `bin/lint.sh` is not one of the byte-identical shared scripts (AGENTS.md lists `pick-issue.sh`, `worktree.sh` and `worktree-done.sh`). The linked `lint.md` contract fixes behaviour, not tool verbosity, so this is fine. |
| R2-F6 | **fixed** | `bin/check-test-annotations.php` is wired as the `test-annotations` step. `composer lint` prints `check-test-annotations: OK — no PHPUnit or PHPBench doc annotations`. The step is documented in `bin/README.md` and AGENTS.md. |

## 3. New diff

### `bin/check-test-annotations.php`

- **Exit codes:**
  - 0 when clean;
  - 1 for findings, written to stderr with `path:line @tag` plus a `FAILED — N` summary;
  - 2 for a usage error (`--root=` empty, unknown argument, missing root);
  - `--help` exits 0.
  - All four paths are covered by `TestAnnotationsLintTest`. An unreadable file is reported as a
    finding, giving exit 1: fail closed.
  - **Gap:** an *unreadable directory* makes `RecursiveDirectoryIterator` throw
    `UnexpectedValueException`. That gives an uncaught exception and exit 255, not 2. Still
    non-zero, so lint fails (R3-F2, nit).
- **Scope:** it scans the same directories as the php-cs-fixer Finder. Directories named
  `vendor`/`var`/`build`/`node_modules` are skipped at any depth, and no such directory exists
  under the scanned roots apart from `e2e/vendor`.
  - It walks the filesystem rather than git, so untracked new files are scanned too. That is
    stricter than round 2, which is good.
- **Tracking:** `.gitattributes` `/bin/* export-ignore` covers the new script, and the `tools/` it
  requires is export-ignored as well, so the dist archive cannot ship one without the other.
- **Shared tag list:** the script `require_once`s `TestAnnotationTags.php` by a relative path and
  does not need the php-cs-fixer autoloader. That is correct: the class has no php-cs-fixer
  dependency.

### Aliased PHPBench regex: false positives and negatives

I probed `TestAnnotationTags::find()` directly.

- **True positives:** `@Revs`, `@Bench\Revs(1000)`, `@\PhpBench\Attributes\Revs(1)`, FQCN
  `Iterations`.
- **Correct negatives:**
  - `@see Foo\Timeout`, `@throws \App\Timeout` and `@return Assert`, because the namespace must
    follow `@` directly;
  - `@phpstan-assert`, because matching is case-sensitive;
  - `@SkipMe` and `@testing`, thanks to the `(?![\w-])` lookahead;
  - `@Foo\requires`, because PHPUnit tags are unqualified;
  - `@var Bench\Revs $b`.
- **False positives (fail closed):**
  - Any namespace in front of a PHPBench short name matches, *including non-PHPBench annotation
    namespaces*: `@ORM\Subject` → `Subject`, `@App\Annotation\Format("x")` → `Format`.
  - The `(?![\w-])` lookahead does not exclude `\`, so a PHPBench name used *as a namespace
    segment* matches too: `@Assert\NotBlank` (the Symfony Validator annotation style) → `Assert`,
    and `@Bench\Revs\Extra` → `Revs`.
  - Prose like `e.g. @Skip this` → `Skip`.
- **Effect:** each of these makes the fixer leave the docblock whole *and* makes `composer lint`
  fail at `test-annotations`. Loud, never silent.
  - The project uses attributes, not Doctrine or Symfony annotations, so none occurs (the real tree
    passes). Recorded as R3-F1 (nit).
  - Cheap tightening: add `\\` to the negative lookahead, `(?![\w\\-])`, which fixes the
    `@Assert\NotBlank` and `@Bench\Revs\Extra` cases. The arbitrary-namespace case is inherent to
    supporting aliases, so accept it.
- **False negatives:** I found none in practice. `@Bench\\Revs` (a double backslash, not valid
  PHPBench) does not match, which is correct.

### `-vv` in `bin/lint.sh`

See R2-F5 above. `lint-fix` (`bin/lint.sh:47`) still uses `-v`, then runs the check pass, which
shows the reason. That is consistent.

### Tests

- **`tests/TestAnnotationsLintTest.php`:**
  - the real tree passes;
  - 6 annotation forms fail, each with `path:5 @tag`;
  - strings, `e2e/vendor` and unrelated tags pass;
  - usage errors.
- **`tests/Tools/NoTestDocAnnotationsTest.php`:**
  - matcher unit tests;
  - Rector pin;
  - two list-sync tests;
  - a pin that `lint.sh` runs the step.
  - The repo-wide PHPUnit scan was removed as a duplicate of the lint step, which is right per
    DEC-008.
- All of them pass (14 tests, 80 assertions; no skips).
- **Nit:** the matcher has no test case for `@Assert\NotBlank`. If R3-F1 is tightened, add one.

### Pre-existing scope observation (not a finding of this PR)

`tests/App/console` is a PHP script without an extension. It is outside both the php-cs-fixer
Finder (`*.php` under `tests/`) and the new scan. It currently has no comments at all, so nothing
escapes. The coder's round-1 finding #3 already covers extensionless PHP scripts.

## 4. Lint and tests

- **`LINT_SKIP_AUDIT=1 composer lint`:** all checks passed, including the new `test-annotations`
  step.
- **Full `composer test` at HEAD 85cf648** (PHP 8.5.10, macOS, grpc loaded):
  - 2979 tests, 19916 assertions, 47 skipped, **1 failure**;
  - the failure is `RestartBackoffTest::testStopSignalEndsTheWaitEarly` ("array contains 9"),
    the known FAQ-007 grpc host failure, confirmed on base 43fc2cb in round 1;
  - nothing else fails.

## 5. New findings

### R3-F1 — nit: the PHPBench regex over-matches namespace segments and foreign annotation namespaces

- **Where:** `tools/php-cs-fixer/TestAnnotationTags.php:30`.
- **What happens:** `@Assert\NotBlank` → `Assert`, `@Bench\Revs\Extra` → `Revs`, and
  `@ORM\Subject` / `@App\Annotation\Format(...)` → a PHPBench tag. It fails closed: lint fails
  and the fixer keeps the docblock. There is no instance in the repo.
- **Fix (optional):** `(?![\w\\-])` closes the first two cases. Add a matcher test for
  `@Assert\NotBlank`.

### R3-F2 — nit: an unreadable directory gives exit 255, not 2

- **Where:** `bin/check-test-annotations.php:78-88`.
- **What happens:** `RecursiveDirectoryIterator` throws on an unreadable subdirectory, and the
  script exits 255 with a PHP stack trace instead of a one-line error. It is non-zero, so lint
  still fails.
- **Fix (optional):** catch `\UnexpectedValueException` around the walk and report it as a
  finding or a usage error.

## Verdict

**Clean round for the coder's scope.**
- Every finding open after round 2 is fixed or accepted:
  - fixed: R2-F1, R2-F2, R2-F5, R2-F6;
  - accepted as a nit: R2-F4.
- The two new findings are optional nits that fail closed.
- Lint is green, and the full suite has only the known FAQ-007 host failure.
- **Still open, for the maintainer and the merger, not the coder:**
  - R1-F3, the licensing notice for `JitterTrigger`;
  - R1-F4, the DEC-018 and DEC-022 KB edits.
- **The PR should not merge until R1-F3 is decided and R1-F4 is applied.**
