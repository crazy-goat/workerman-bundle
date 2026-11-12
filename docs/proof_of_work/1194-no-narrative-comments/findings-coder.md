# Findings — coder (#1194)

## Biggest problem: loading php-cs-fixer classes for PHPStan (level 8) without lowering a gate

- **The setup.** `php-cs-fixer/shim` is a phar with unscoped bundled dependencies, including its
  own Symfony Console, Finder and OptionsResolver. The fixer has to extend `PhpCsFixer\AbstractFixer`
  and use `Tokens`, `Token` and `TypeExpression`. PHPStan analyses every linted path at level 8, so
  the class has to be analysable and the phar classes discoverable.
- **The shim's autoloader is unusable.** Requiring `phar://…/vendor/autoload.php` prepends a Composer
  loader that would shadow the project's Symfony.
- **Fix:** `tools/php-cs-fixer/autoload.php` appends a PSR-4 loader for `PhpCsFixer\` only, pointing
  at `phar://…/php-cs-fixer.phar/src/`. PHPStan uses it through `bootstrapFiles`, Rector through
  `withBootstrapFiles`, and php-cs-fixer through `require_once`.
- **Second obstacle.** The phar's sources are built with every docblock stripped, so PHPStan does
  not know `Tokens extends \SplFixedArray<Token>`. `$tokens[$i]` reported `offsetAccess.notFound` and
  `offsetAssign.dimType`.
  - A stub file restoring the generic failed: PHPStan reported `Token` as an unknown class inside
    the stub.
  - Solution: no ignore entries. Read through `tokenAt()` (`offsetGet()` + `instanceof Token` guard),
    write through `offsetSet()`.
- **Rector side effects.** Rector added `@param Tokens<Token>` docblocks in FQCN form, and
  re-adds them when they are shortened. It also hoisted `count($tokens)` out of the loop, which
  would skip the last token after an `insertAt()`; I changed that path to `offsetSet()`.

## Surprises

- **`no_superfluous_phpdoc_tags` empties docblocks.** It runs after the new fixer and removes a
  bare `@var string|false` that duplicates the native type. That left `/**  */` in two tests.
  `no_empty_phpdoc` was not enabled in `.php-cs-fixer.dist.php`; I added it (a raised gate).
- **Rector skips `@covers`.** Its PHPUnit set leaves `@covers` alone on PHPUnit 10, because
  `CoversAnnotationWithValueToAttributeRector` requires the PHPUnit 11 `CoversMethod`/`CoversTrait`
  classes. I converted the 15 by script to `#[CoversClass]`.
- **`@PER-CS2x0`'s `no_extra_blank_lines` only targets `use`.** It would not collapse blank-line runs
  left by a removed comment, so the fixer handles the whitespace it touches itself.
- **The local `vendor/` was stale** (no `symfony/yaml`, Workerman 5.2.2 instead of the locked
  5.2.3). PHPStan reported 21 errors until `composer install`. A worktree created before #993/#1184
  needs `composer install`.

## Bugs / weak spots found (incl. out of scope)

1. **`tests/Worker/RestartBackoffTest.php:199` fails locally on `43fc2cb` (origin/master) as well,
   unrelated to this branch.**
   - Symptom: `testStopSignalEndsTheWaitEarly` sees child exit code 9 instead of 0/137 on macOS
     (Darwin 27), PHP 8.5.10, Workerman v5.2.3.
   - Reproduced 2/2 at the base commit and 3/3 on the branch.
   - It probably comes from the child dying via SIGKILL-like behaviour in Workerman 5.2.3's
     `signalHandler()` path, or from the macOS signal setup.
   - Suggested fix: check what `tests/Fixtures/restart_backoff_signal.php` exits with on Darwin, and
     whether CI (Linux) passes. If it is Darwin-only, add `9` to the accepted set with a reason, or
     mark it `#[RequiresOperatingSystem('Linux')]`.
2. **A forgotten PHPUnit doc annotation now disappears silently.** If a contributor writes
   `/** @dataProvider foo */`, `lint-fix` deletes it (not a kept tag). The test then fails loudly
   with an argument-count error, so it is caught.
   - But `@group`, `@requires` or `@covers` would just vanish with no failure. Example: a `@requires
     extension inotify` test would then run, and fail, on hosts without inotify.
   - Suggested gate: a test or a Rector rule that rejects doc annotations, or keep
     `PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES` in `rector.php`. Rector runs before php-cs-fixer in
     `lint-fix`, so annotations would be converted before they are stripped. That is harmless and
     cheap; I did not add it because the brief said "don't necessarily keep".
3. **`.php-cs-fixer.dist.php` only finds `*.php`.** The `bin/` scripts without an extension that are
   PHP (none today besides `e2e/console`, which is now appended) would be skipped. Watch this when
   adding PHP CLI scripts without `.php`.
4. **`tests/Tools/NoNarrativeCommentsFixerTest.php` spawns about 28 php-cs-fixer processes (~7 s).**
   Running all cases in one process over a directory would be faster, but a per-case run gives
   precise failures. Revisit if suite time matters.
5. **Rector `AddParamTypeSplFixedArrayRector` and short-name generics.** It cannot see that
   `@param Tokens<Token>` (short names) already satisfies it, and keeps rewriting it to FQCN. This is
   a minor Rector quirk; the FQCN form is accepted as is.

## Comments that looked load-bearing (deleted per maintainer decision)

They were deleted as decided; listed so the maintainer can move any of them to `docs/` or
`docs/helpers/`:

- **`src/Http/Request.php`, class + `setHeader()` + `withHeader()`:** the X-Forwarded / `Forwarded`
  re-injection security warning (trusted-proxy bypass).
  - It is still covered by `docs/security.md` "Middleware Header Re-Injection", now pinned by
    `RequestTest::testSecurityDocsWarnAboutForwardedHeaderReInjection`.
  - IDE users of the public API lose the inline warning.
- **`src/ConfigLoader.php` (`checkCacheFilePermissions()` / `validateCacheFilePermissions()`):**
  - the 4-point ownership and permission model of the config cache (`require`d PHP, attacker-writable
    directory);
  - the TOCTOU note;
  - the "documented security deviation" of the trust override.

  Check that `docs/security.md` covers all four points.
- **`src/ConfigCacheGuardConfig.php`:** fail-closed parsing ("a typo must never silently unlock a
  security guard").
- **`src/DTO/RequestConverter.php`:**
  - the method-name allow-list rationale (routing bypass);
  - first-value-wins for security-sensitive duplicate headers (#217);
  - cookie decode-after-split ordering ("an encoded `%3B` would …");
  - the 1000-element cap deviation.
- **`src/Phar/SfxDownloader.php`:**
  - zip-slip validation and symlink entries materialised as files;
  - "a tampered archive must never reach ZipArchive";
  - redirects followed manually, never by the PHP wrapper;
  - rollback rules (only remove paths that were extracted).
- **`src/Phar/ExcludePattern.php`, `src/Phar/PcreLimitGuard.php`:** ReDoS defence (#334).
- **`src/ProcessInspector.php`:** "fail closed, never open — a live master is never read as dead",
  and the non-Linux availability-over-strictness trade-off.
- **`src/Middleware/StaticFilesMiddleware.php`:**
  - symlink policy (`$followSymlinks` default off);
  - emacs autosave `#index.php#` bypass note;
  - "DELETE to a static path never returns the file" (#903);
  - extension rules (#637).
- **`src/Middleware/SymfonyController.php`:** spoofed-Host validation and caching only validated
  hosts; "Do NOT set initialized → next request will retry".
- **`src/Http/HttpRequestHandler.php`:**
  - client errors logged at debug level against log flooding (#577);
  - "do not hoist" the static-files layer (DEC-022);
  - the throwable must not escape `__invoke()`.
- **`src/Http/Response/Strategy/BinaryFileResponseStrategy.php`, `StreamedResponseStrategy.php`:**
  - HEAD bodyless rules (RFC 9110 §9.3.2, #643/#683);
  - weak references so handlers are not retained;
  - self-removing `onBufferDrain` callback.
- **`src/Scheduler/Trigger/JitterTrigger.php`:** `@author Kevin Bond <kevinbond@gmail.com>`. Check
  the attribution and licence if the code was copied from symfony/scheduler.
- **`@deprecated` replacement hints:** now bare. They were `Utils::*` "use Utils::reload() instead,
  removed in 1.0" and `Request::withHeader()` "use setHeader()". `UPGRADE.md` still documents both.
- **`@internal Test affordance only. Production code must not call this.`:** now a bare `@internal`
  on the `reset()`/`clear()` helpers.

## Knowledge-base candidates (for the merger; I did not edit docs/helpers/)

- **DEC — Comments are not documentation: only bare docblock tags and tool directives (#1194)**
  - tags: `lint`, `policy`, `docs`, `tests`
  - trigger: "writing a comment or docblock, or a lint failure from `CrazyGoat/no_narrative_comments`"
  - The fixer in `tools/php-cs-fixer/` keeps only type tags (`@param`/`@param-out`/`@return`/`@throws`/`@var`),
    bare `@deprecated`/`@internal`, `@see <target>`, and `@phpstan-*`/`@psalm-*`/`@template*` verbatim.
    Line and block comments survive only as tool directives.
  - Rationale goes into the issue, the PR, `docs/` or `docs/helpers/`.
  - Generics must use the `@phpstan-` tag forms; PHPUnit and PHPBench metadata must be attributes.
  - Gate: `composer lint`.
- **FAQ — php-cs-fixer shim classes in PHPStan: append a PSR-4 loader into the phar, never require its autoload.php**
  - tags: `lint`, `phpstan`
  - trigger: "a tool under tools/ that extends php-cs-fixer classes"
  - The shim phar is unscoped, and its Composer autoloader is prepended and would shadow the
    project's Symfony.
  - `tools/php-cs-fixer/autoload.php` maps only `PhpCsFixer\` into `phar://…/src/`.
  - The phar's docblocks are stripped, so access `Tokens` via `offsetGet()`/`offsetSet()` and an
    `instanceof Token` guard rather than `[]`.
- **FAQ — Rector's PHPUnit annotation set skips `@covers` on PHPUnit 10**
  - tags: `tests`, `lint`
  - trigger: "migrating PHPUnit annotations to attributes"
  - `CoversAnnotationWithValueToAttributeRector` bails out unless the PHPUnit 11 `CoversMethod`/`CoversTrait`
    exist. Convert class-level `@covers \X` to `#[CoversClass(X::class)]` by hand.
  - `@covers ::method` has no PHPUnit 10 attribute.

### Entries that now point at deleted docblocks

- **DEC-018** (`docs/helpers/decisions.md` ~line 302) says "the handler's docblock documents the
  allocation model". That docblock (`HttpRequestHandler` / `MiddlewareDispatcher`) is gone.
  Suggested: move the allocation-model sentence into the DEC entry.
- **DEC-022** (~line 309) says "the `withRootDirectory()` docblock points here". It no longer does.
  Suggested: drop that clause.
- **DEC-020** mentions docblocks only as the reason the exception-usage check uses tokens. Still
  valid, no change needed.

## Round 2

### Biggest problem: making an unparsable type fail visibly

- Throwing from the fixer makes php-cs-fixer exit 64 and list the file under "not fixed due to
  errors", but with `-v` (what `bin/lint.sh` uses) the exception message itself is hidden; only
  `-vv` prints it. The test runs with `-vv`. Lint still fails and the output says to re-run with
  `-vvv`, so the failure is visible, but the reason needs one more command.
- Suggested improvement (not done, it changes the shared lint script): run php-cs-fixer with `-vv`
  in `bin/lint.sh`, or print a hint when it exits 64.

### Surprises

- `tests/BinDirectoryTest.php:347` (`testNoTrackedDocMentionsTheRemovedProcessTooling`) forbids
  every tracked Markdown file from naming the workflow scratch files or the proof-of-work directory.
  Committed proof-of-work files therefore break `composer test` as soon as they name them. The
  round-1 review did (line 46); I reworded that one line in a separate commit.
  - Suggested fix: exclude the proof-of-work directory from that scan, or drop those names from the
    forbidden list now that proof-of-work files are committed again. Until then, coders and
    reviewers must avoid those literal names in committed Markdown.
- Rector's `LocallyCalledStaticMethodToNonStaticRector` rewrote a new private static helper in the
  fixer test; applied.

### Weak spots

1. `tools/php-cs-fixer/TestAnnotationTags.php`: the list is hand-maintained. A new PHPUnit or
   PHPBench annotation not on the list would still be stripped silently. Low risk (PHPUnit 12 drops
   annotations entirely), but worth checking when bumping PHPBench.
2. The fixer test now spawns about 40 php-cs-fixer processes (~10 s).

### Knowledge-base candidate

- **FAQ — committed proof-of-work Markdown must not name the workflow scratch files**
  - tags: `tests`, `docs`, `workflow`
  - trigger: "`testNoTrackedDocMentionsTheRemovedProcessTooling` fails on a proof-of-work file"
  - The test scans every tracked `.md`. Refer to "the review findings file" instead of its name,
    or fix the test as suggested above.
