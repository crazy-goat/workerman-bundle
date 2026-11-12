# Review round 1 — #1194 (review-critical)

Diff: `git diff 43fc2cb...HEAD` (7 commits, 248 files, +1964 / −8843).

## 0. Trigger

`review-critical` applies on several criteria:
- the diff touches `src/Http` (`Request`, `HttpRequestHandler`, `Response/*`, strategies);
- it touches security-relevant code: `ConfigLoader`, `ConfigCacheGuardConfig`, `SfxDownloader`,
  `PcreLimitGuard`, `StaticFilesMiddleware`, `RequestConverter`;
- it touches process supervision: `ProcessInspector`, `ProcessTerminator`, `Utils`, `Worker/*`;
- it touches docblocks of public interfaces (`ResponseConverterStrategyInterface`,
  `RequestMethodAwareResponseConverterStrategyInterface`, `MiddlewareInterface`,
  `TriggerInterface`, `RebootStrategyInterface`);
- it changes far more than 200 lines.

## 1. Knowledge base (tag index: lint, policy, tests, phpstan, phpbench, http, middleware, docs)

Entries read: DEC-007, DEC-008, DEC-014, DEC-015, DEC-018, DEC-020, DEC-021, DEC-022,
FAQ-007, FAQ-028, FAQ-029, FAQ-031, FAQ-035, FAQ-037.

- **DEC-008:** respected. The rule is wired through `bin/lint.sh`: php-cs-fixer dry-run in
  `lint`, Rector then php-cs-fixer in `lint-fix`. No new standalone entry point.
- **DEC-007, gates:** nothing was lowered.
  - Gates were raised: `tools/` was added to the PHPStan (level 8, no ignore, no baseline), Rector
    and php-cs-fixer scopes; `no_empty_phpdoc` was enabled.
  - The coverage floor is untouched.
- **FAQ-029:** `@param-out string $lower` on `HeaderNameNormalizer::normalize()` survived. Only
  the redundant `@param ?string $lower` went, removed by `no_superfluous_phpdoc_tags`.
- **FAQ-031:** `bin/` is still in scope. `e2e/` and `tools/` were added.
- **FAQ-037:** no PHP 8.3+ syntax in `tools/` or `tests/Tools/` (no typed constants,
  `#[\Override]` or anonymous readonly classes). The fixer test passes on PHP 8.4.25 and 8.5.10.
  No 8.2 binary on this host; the CI 8.2 leg is the net.
- **DEC-018 / DEC-022, violations by staleness:**
  - DEC-018 says "the handler's docblock documents the allocation model".
  - DEC-022 says "the `withRootDirectory()` docblock points here".
  - Both docblocks are deleted, so both entries now describe something that does not exist. The
    merger must fix these in this PR (F4).
- **FAQ-028 (title) and FAQ-035 (body):** still use annotation syntax (`@ParamProviders`,
  `@dataProvider`). Nit, part of F4.
- **DEC-014 / DEC-015:** the `@internal` test affordances remain, as bare `@internal`. The
  cookie deviation stays documented in `docs/security.md`, so both are fine.

## 2. Earlier findings

None. This is round 1 and `findings-review.md` did not exist.

## 3. Mechanical verification

All scripts are in `/tmp/rv1194/`. They are not committed.

### Non-comment tokens

**Check.** For every modified file, I compared the `token_get_all()` stream of parent and commit,
with `T_COMMENT`, `T_DOC_COMMENT` and `T_WHITESPACE` filtered out.
- **948ddab (mass cleanup), 230 files:** only one file differs, `.php-cs-fixer.dist.php`.
  `declare(strict_types=1)` was added because the file joined the Finder; that change is harmless.
  Every other file is token-identical: no code, heredoc or string damage, and no behaviour change.
- **03c11c3:** only `tests/RequestTest.php`, as intended.
- **090cd55:** only `.php-cs-fixer.dist.php` and `rector.php` among modified files, as intended.

### Type tags and directives (independent parser)

**Check.** I parsed every `T_DOC_COMMENT` before and after with PHPStan's own `phpdoc-parser` (from
`phpstan.phar`), not with php-cs-fixer's `TypeExpression`. That gives an independent check of the
fixer. I compared multisets of these:
- `@param`/`@param-out`/`@return`/`@throws`/`@var` with type, variable name, `...` and `&`;
- `@phpstan-*`, `@psalm-*` and `@template*`;
- `@deprecated`, `@internal`, `@see` and `@codeCoverageIgnore*`;
- every directive `T_COMMENT`.

**Results.**
- No tag was gained, and no directive was lost (every `@phpstan-ignore*` survived).
- 32 files lost tags. Every lost tag is a scalar, class, `mixed` or `callable` type that exactly
  duplicates the native signature. Examples: `@param bool $installed`, `@return \ZipArchive`,
  `@param (string|null) $osFamily`.
  - They were removed by `no_superfluous_phpdoc_tags` once their description was gone. That is
    the expected interaction.
  - No `array`/shape/generic/`list`/conditional type was lost; PHPStan level 8 would have flagged
    a missing iterable type.

### Attribute migration (e4170fc)

**Check.** I compared (owner class/method, kind, argument) tuples from base annotations plus base
attributes against head attributes.

**Results.**
- All 215 `@requires` map 1:1, with matching counts per value:
  - `extension` inotify 18, pcntl 74, posix 71, zip 22;
  - `OS` Darwin 8, Linux 20, `Linux|Darwin` 1, Windows 1.
- On the same method or class:
  - all 53 `@dataProvider`, 27 `@coversNothing`, 5 `@group` and 2 `@depends`;
  - the 15 `@covers \Fqcn`, now `#[CoversClass]` with the right class after resolving `use`;
  - all PHPBench `BeforeMethods`/`AfterMethods`/`Revs`/`Iterations`/`Warmup`/`ParamProviders`.
- The only differences my script reported were pre-existing FQCN `#[\PHPUnit\…]` attributes that
  my regex missed on the base side. I checked them by hand, so these are false positives.
- No `@dataProviderExternal`, `@testWith`, `@covers ::method` or `@runInSeparateProcess` existed.

### Fixer behaviour on edge cases (sandbox config, rule alone)

**Correct.**
- Types: `callable(int, string): bool`, `...$rest`, `&$out`, the conditional
  `($cb is null ? string : int)`, `$this`, `static|null`, array shapes with optional keys,
  `\Closure(): void|null`, `iterable<int, array{0: string, 1: int}>`, a multi-line generic
  (`array<\n string,\n int\n>`), `int<0, max>`, literal unions, `Foo::CONST_*`, `non-empty-string`.
- Content that must not change: strings, heredocs and attributes (`#[Attr] // trailing` keeps the
  attribute). The `e2e/console` shebang is preserved.
- Comments: a trailing comment after `[` is removed cleanly. Multi-line descriptions after a typed
  tag are dropped.
- The output is idempotent (dry-run re-run exits 0).

**Broken:** see F1 (token gluing) and F7 (two tags in a one-line docblock).

## 4. Lint and tests

- **`LINT_SKIP_AUDIT=1 composer lint`:** all checks passed (php-cs-fixer, PHPStan level 8,
  Rector, kb-lint, changelog, exception-usage, upgrade-exceptions, shellcheck, hadolint).
- **`vendor/bin/phpunit tests/Tools`:** 15 tests, 50 assertions, OK on PHP 8.5.10 and 8.4.25.
  The only warning is the missing coverage driver.
- **`RequestTest --filter Security`:** OK.
- **`RestartBackoffTest::testStopSignalEndsTheWaitEarly`: confirmed failing on base 43fc2cb.**
  - Setup: a detached worktree at 43fc2cb.
  - Result: `Failed asserting that an array contains 9`, at `tests/Worker/RestartBackoffTest.php:205`
    on base and `:199` on head.
  - Caveat: `vendor/` was symlinked from the main checkout, so the Composer autoloader may resolve
    `src/` to HEAD. HEAD's `src/` is token-identical to base apart from comments, so the
    conclusion holds.
  - Cause: this host loads `grpc`. Per FAQ-007, `ProcessTerminator` then SIGKILLs the child and
    `proc_close()` returns the signal number (9), not the shell-style 137 the test accepts.
  - This is pre-existing, specific to macOS with grpc, and unrelated to #1194.
  - Follow-up candidate: accept `9` as well as `137`, or derive the expected code from
    `extension_loaded('grpc')`.
- **The full `composer test` was not re-run in this round.** The coder reports 2936 tests and
  identical `--list-tests` output after the migration. The token check above proves the
  `src/`/`tests/` code is unchanged by the mass commit.

## 5. Findings

### F1 — low: removing an inline comment can glue tokens into a different token

- **Where:** `tools/php-cs-fixer/NoNarrativeCommentsFixer.php:299-302`.
- **What happens:**
  - `echo $a-/* x */-$b;` becomes `$a--$b`.
  - `$a+/**/+$b` becomes `$a++$b`.
  - The no-glue guard only inserts a space when both neighbours are word characters.
- **Effect:** php-cs-fixer's post-fix lint catches the parse error and refuses to write the file
  ("Files that were not fixed due to errors reported during linting after fixing"). There is no
  silent damage, but `lint-fix` cannot fix such a file and the error does not name the cause.
  - If the glued result happened to stay valid PHP, the behaviour change would be silent. Example:
    `$a./**/5` becomes `$a.5`, which PHP rejects today, but the class of bug is real.
- **Fix:** when neither side is whitespace, always replace the comment with a single space and let
  the whitespace fixers normalise it. Add the `-/**/-` and `+/**/+` cases to
  `NoNarrativeCommentsFixerTest`.
- **Check that would catch it:** that fixer test case. A per-file token-stream equality assertion
  in the fixer test (non-comment tokens before == after) would catch the whole class of bug.

### F2 — medium: a forgotten PHPUnit/PHPBench doc annotation is deleted silently by `lint-fix`

- **Where:** `tools/php-cs-fixer/NoNarrativeCommentsFixer.php:207-209`, where non-kept tags are
  dropped, together with `rector.php`.
- **Verified:**
  - Input: `/** @requires extension inotify\n @group e2e */` on a test method.
  - After `fix`, the whole docblock is gone and nothing fails.
  - Result: the test now runs (and fails, or worse passes vacuously) on hosts without the
    extension, and the group filter changes without notice.
  - `@dataProvider` at least fails loudly with an argument-count error. `@requires`, `@group`,
    `@covers`, `@depends` (the test runs without its dependency), `@Revs` and `@BeforeMethods`
    do not.
- **Policy:** the maintainer decided PHPUnit/PHPBench annotations *become attributes*. Deleting
  them instead breaks that policy.
- **Fix (the gate belongs in this PR, since the defect class is new and this PR creates it):**
  - Keep `PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES` in `rector.php`. Rector runs before
    php-cs-fixer in `lint-fix` (`bin/lint.sh:46-47`), so annotations are converted before they are
    stripped, and `lint` (Rector dry-run) fails on a leftover annotation.
  - That set does not handle `@covers` on PHPUnit 10, and there is no Rector set for PHPBench. So
    in addition, make the fixer *leave* any docblock containing a PHPUnit/PHPBench annotation tag
    (`@dataProvider*`, `@requires`, `@covers*`, `@uses`, `@group`, `@depends`, `@test*`,
    `@before*`, `@after*`, `@runInSeparateProcess`, `@BeforeMethods`, `@Revs`, …) untouched.
  - Then add a PHPUnit test (style of `ExceptionUsageLintTest`) that fails when any such tag
    exists in `tests/` or `benchmarks/`.
- **Check that would catch it:** Rector dry-run plus that scan test.

### F3 — medium (licensing, maintainer decision): `JitterTrigger` provenance marker removed

- **Where:** `src/Scheduler/Trigger/JitterTrigger.php`, where `@author Kevin Bond` was deleted in
  948ddab.
- **Origin:** the class is evidently derived from symfony/scheduler's `JitterTrigger`. Kevin Bond
  is its author, and the `__toString()` format `'%s with 0-%d second jitter'` is identical.
- **Licence:** Symfony is MIT. MIT requires the copyright and permission notice
  ("Copyright (c) Fabien Potencier") in all copies or substantial portions.
  - Base already lacked Symfony's file header, so the notice gap pre-exists this PR.
  - But the `@author` line was the only provenance marker left. After this PR nothing in the
    repository says the code comes from Symfony.
  - The bundle is MIT too, so a notice is enough.
- **Fix:** comments are not allowed by policy, so add a "Third-party code" section to `LICENSE`
  or a `NOTICE` file. It should name `src/Scheduler/Trigger/JitterTrigger.php`, symfony/scheduler
  and Symfony's copyright line. Also audit the sibling triggers from the same commit (0dab8ee),
  `PeriodicalTrigger` and `CronExpressionTrigger`, for the same origin.
- **Check that would catch it:** none automated. The candidate KB entry below records the rule.
- I am not a lawyer; this is flagged for the maintainer.

### F4 — low: KB entries point at deleted docblocks

- **Where:**
  - `docs/helpers/decisions.md:302` (DEC-018: "the handler's docblock documents the allocation
    model");
  - `docs/helpers/decisions.md:309` (DEC-022: "the `withRootDirectory()` docblock points here").
- **Fix:** both are false after 948ddab. The merger edits them in this PR:
  - move the allocation-model sentence into DEC-018;
  - drop the DEC-022 clause.
- **Nit:** FAQ-028's title (`@ParamProviders`) and FAQ-035 (`a @dataProvider`) should use the
  attribute names.
- **Check that would catch it:** `bin/kb-lint.php` could warn on the words
  "docblock"/"comment … points here" in active entries, since the policy guarantees none exist
  outside tags. That is optional; prefer the edit.

### F5 — nit: directive detection matches anywhere in the comment

- **Where:** `tools/php-cs-fixer/NoNarrativeCommentsFixer.php:20`.
- **What happens:** `// we used to need phpcs: here` or `// remove @phpstan-ignore once X` survive
  whole, and a directive comment keeps any trailing narrative.
- **Fix:** anchor the regex to the start of the comment body
  (`~^(?://|#|/\*)\h*(?:@phpstan-ignore|@codeCoverageIgnore|phpcs:|@psalm-suppress)~i`).
  - Note: PHPStan's own `@phpstan-ignore id (reason)` syntax legitimately carries a reason; that
    is fine to keep.

### F6 — nit: when a typed tag's type does not parse, the description stays

- **Where:** `tools/php-cs-fixer/NoNarrativeCommentsFixer.php:220-222` (`return $group;`).
- **What happens:** if `TypeExpression::REGEX_TYPES` does not match, the tag *and its narrative*
  are kept verbatim, and `lint` passes. It is conservative (it never breaks a type), but it is a
  silent escape from the rule. There is no instance in the repo today.
- **Fix:** a test case documenting the fallback. Optionally, keep only the first line.

### F7 — nit: a one-line docblock with two tags loses the second

- **What happens:** `/** @param int $a @param int $b */` becomes `/** @param int $a */`. That is
  rare and arguably malformed, and there is no instance in the repo.
- **Fix:** a test case pinning the behaviour, or keep it verbatim.

### F8 — nit: `autoload.php` hard-codes the vendor path

- **Where:** `tools/php-cs-fixer/autoload.php:8`.
- **What happens:** the path is `dirname(__DIR__, 2) . '/vendor/php-cs-fixer/shim/php-cs-fixer.phar'`,
  so a custom `COMPOSER_VENDOR_DIR`/`config.vendor-dir` breaks it.
- **Assessment:**
  - It is independent of cwd, so the path is robust in CI.
  - The loader is appended and maps only `PhpCsFixer\`.
  - When php-cs-fixer itself runs, its own (prepended) loader resolves first, so this one is a
    no-op.
  - Under PHPStan and Rector, no Symfony class can be shadowed.
  - Acceptable as is.

### Non-findings, checked

- **No code damage in the mass commit:** proven by the token stream comparison (§3).
- **Comments that looked load-bearing:** the security rationale removed from `ConfigLoader`
  (ownership, permission and TOCTOU model, trust override) is covered by `docs/security.md`
  §"Config cache" (lines ~420–485). The header re-injection warning is now pinned by
  `RequestTest::testSecurityDocsWarnAboutForwardedHeaderReInjection`.
- **Runtime docblock readers:** nothing in `src/` or `tests/` reads docblocks at runtime
  (`getDocComment` has no hits).
- **`.gitattributes` and docs:**
  - `/tools export-ignore` was added.
  - The CHANGELOG entry carries `(#1194)` and `bin/check-changelog.php` passes.
  - CONTRIBUTING and AGENTS were updated.
- **Gates:** none was lowered. One gate was raised (`no_empty_phpdoc`).
- **Fixer priority 40:** it runs before `align_multiline_comment`, `no_superfluous_phpdoc_tags`,
  `no_empty_phpdoc` and `no_unused_imports`. The repo-wide dry-run is clean, which proves the
  fixer chain is idempotent.

## 6. Candidate KB entries (for the merger)

- **DEC — Third-party derived code carries its notice in LICENSE/NOTICE, not in a comment**
  - tags: `policy`, `docs`, `lint`
  - trigger: "copying or adapting code from another project (e.g. symfony/*)"
  - Since #1194 the comment rule removes `@author` and file headers. Attribution for derived code
    (MIT requires the copyright and permission notice) therefore lives in a third-party section of
    `LICENSE` or in `NOTICE`, naming the file and the source.
- **FAQ — Doc annotations are deleted, not converted, by `lint-fix`** (only if F2 is not gated)
  - tags: `tests`, `lint`, `phpbench`
  - trigger: "writing `@requires`/`@group`/`@covers`/`@Revs` in a docblock"
  - The no-narrative fixer drops any tag it does not keep. Use PHPUnit/PHPBench attributes.
  - Prefer the gate in F2 over this entry.
- **FAQ — `proc_close()` returns the signal number (9) on macOS when grpc forces SIGKILL**
  - tags: `tests`, `grpc`, `macos`, `process`
  - trigger: "`RestartBackoffTest` expecting 137, got 9"
  - An extension of FAQ-007, or a follow-up issue instead.

## Verdict

**Changes requested.**
- **F2 must be gated in this PR.** The policy says annotations become attributes; silent deletion
  of `@requires`/`@group` is a regression path this PR introduces.
- **F3 needs a maintainer decision** before merge.
- **F1** is cheap to fix with a test.
- **F4** is the merger's KB edit.
- The rest are nits.
