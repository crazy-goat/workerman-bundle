# Code decision — round 1 (#1194)

## Approach

Five commits on `refactor/issue-1194-lint-rule-forbid-narrative-comments-allo`:

1. `refactor(tests)`: PHPUnit and PHPBench annotations migrated to attributes.
   - Ran Rector's `PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES` once from a throwaway config
     (`/tmp`, not kept in `rector.php`). It converted `@dataProvider`, `@requires`,
     `@coversNothing`, `@group` and `@depends`.
   - It skipped `@covers`, because its rule bails out when the PHPUnit 11 `CoversMethod`/`CoversTrait`
     attributes do not exist. All 15 `@covers` were class-level `@covers \Fqcn`, so a one-off script
     turned them into `#[CoversClass(\Fqcn::class)]`. The same script shortened Rector's FQCN
     attributes to imported short names, the existing convention in `tests/`.
   - The repository has no `@covers ::method`, so nothing needed a decision there. If one shows up
     later, PHPUnit 10 has no method-level equivalent; use `#[CoversClass]` on the class.
   - Test list before and after: identical, `vendor/bin/phpunit --list-tests | sort` diff empty
     (2917 lines). Groups are unchanged (`e2e`, `http`, `network`).
   - PHPBench: a one-off script moved `@BeforeMethods/@AfterMethods/@Revs/@Iterations/@Warmup/@ParamProviders`
     to `PhpBench\Attributes\*`. Checked with
     `phpbench run --executor=debug --report=aggregate`: same 45 rows (benchmark, subject, set,
     revs, its) before and after. A real `--revs=1 --iterations=1` run also passed, so
     `BeforeMethods`/`AfterMethods` still fire.
2. `feat(lint)`: the fixer, `tools/php-cs-fixer/NoNarrativeCommentsFixer.php` (`CrazyGoat/no_narrative_comments`).
   - The commit also adds `tools/php-cs-fixer/autoload.php`, the config wiring, PHPStan and Rector
     paths, `.gitattributes` and `tests/Tools/NoNarrativeCommentsFixerTest.php`.
   - It also enables `no_empty_phpdoc`, see "Surprises".
3. `test(http)` (hand fix, before the mass run so every commit stays green):
   - `RequestTest` asserted that the `setHeader()`/`withHeader()` docblocks contain the
     `X-Forwarded` security warning.
   - That warning lives canonically in `docs/security.md` ("Middleware Header Re-Injection"). The
     two tests became one test pinning it there.
4. `style`: pure output of `composer lint-fix`. 230 files, +202 / −8129 lines.
5. `docs`: CONTRIBUTING.md (code standards and the `#[RequiresPhpExtension]` wording), AGENTS.md
   layout row for `tools/`, and the CHANGELOG entry.

### Fixer design

- **Docblocks.** The fixer splits the docblock into lines (keeping each line's ` * ` prefix) and
  groups them into tag groups. A group starts at an `@tag` line and continues until the next tag or
  a blank line. Free text outside groups is dropped.
- **Typed tags** (`param`, `param-out`, `return`, `throws`, `var`). The fixer joins the group's
  lines and matches `@tag` + `PhpCsFixer\DocBlock\TypeExpression::REGEX_TYPES`, then an optional
  `&`/`...`/`$name` for the named tags. It keeps exactly that prefix and re-emits it line by line
  with the original prefixes. Multi-line array shapes therefore survive byte-for-byte. Over the whole
  repo, every shape (e.g. `WorkermanBundle::loadExtension()`'s 60-line `$config`) survived.
- **Fallback.** If the type cannot be parsed, the group is kept verbatim (conservative: never break
  a type). After the mass run the repo contains no such fallback; checked with a token scan.
- **Other tag kinds.** `@phpstan-*`, `@psalm-*` and `@template*` are kept verbatim, including
  continuation lines (the `// @deprecated` lines inside `ServerWorker`'s `@phpstan-type` survive).
  `@deprecated`, `@internal` and `@codeCoverageIgnore*` become bare. `@see` keeps its first word.
  Everything else is removed. A docblock left empty is removed.
- **One-liners.** A one-line docblock is re-emitted as `/** … */`.
- **`T_COMMENT`.** Removed unless it matches `@phpstan-ignore|@codeCoverageIgnore|phpcs:|@psalm-suppress`.
  Shebangs (`T_INLINE_HTML`), attributes (`T_ATTRIBUTE`), strings and heredocs are separate token
  kinds and never touched.
- **Whitespace when removing a token.**
  - A whole-line comment: the surrounding whitespace is merged, with at most one blank line kept,
    or none next to `{ ( [` / `} ) ]`.
  - A trailing comment: the preceding horizontal space goes.
  - An inline comment: one side's space goes.
  - `return/* x */1` becomes `return 1`, so tokens are never glued.
- **Priority 40.** The fixer runs before `align_multiline_comment` (27),
  `no_superfluous_phpdoc_tags` (6), `no_empty_phpdoc` (3), `no_unused_imports` (−10) and the
  whitespace fixers.

### Where the class lives and how it loads

- **Location: `tools/php-cs-fixer/`**, a new contributor-only top-level dir, export-ignored.
  - `bin/` is for executable scripts (and `bin/README.md` must document each one).
  - `src/` ships to users.
  - `tests/` is excluded from the PSR-4 production autoload and is the wrong home for a linter.
- **Loading.** `php-cs-fixer/shim` is an unscoped phar that bundles its own Symfony components, so
  its `vendor/autoload.php` cannot be loaded next to the project's autoloader.
  `tools/php-cs-fixer/autoload.php` registers an *appended* PSR-4 loader for only two namespaces:
  `CrazyGoat\WorkermanBundle\Tools\PhpCsFixer\` → `tools/php-cs-fixer/` and
  `PhpCsFixer\` → `phar://…/php-cs-fixer.phar/src/`.
  - It is used by `.php-cs-fixer.dist.php` (`require_once`), by PHPStan (`bootstrapFiles`) and by
    Rector (`withBootstrapFiles`).
  - PHPStan analyses `tools/` at level 8 with no ignore entry and no baseline.
- **Tokens access.** The phar's sources have their docblocks stripped, so PHPStan cannot see
  `Tokens extends SplFixedArray<Token>`. The fixer reads tokens through a small `tokenAt()`
  (`offsetGet` + `instanceof Token`) and writes through `offsetSet()`, not through `[]`.
  - I tried a stub (`phpstan-stubs/php-cs-fixer.stub`) first. PHPStan then reported the stub's own
    `Token` reference as unknown, so I dropped it.
- **Finder scope.** It now includes `tools/`, `e2e/` (excluding `vendor`, `var`, `build`),
  `e2e/console`, `.php-cs-fixer.dist.php` and `rector.php`. "All PHP in the repo" therefore
  includes the root configs. As a result, the per-rule explanatory comments in
  `.php-cs-fixer.dist.php` were removed too.

### Tests

`tests/Tools/NoNarrativeCommentsFixerTest.php` drives the real `vendor/bin/php-cs-fixer` binary as a
subprocess, with a sandbox config that enables only this rule.

- **Why a subprocess.** Loading the phar classes into PHPUnit would put the phar's bundled Symfony
  classes next to the project's Symfony 6.4–8.1 matrix. A subprocess also tests the real config
  loading path.
- **Cases (13, one per data set).**
  - Each typed tag with and without a description.
  - Multi-line array shapes, with a description continued on the next line.
  - `&$out`, `...$rest` and `@param-out`.
  - `@phpstan-*`, `@psalm-*` and `@template` kept verbatim (including a `//` line inside a shape).
  - `@deprecated`, `@internal` and `@see` losing their descriptions; `@author` and `@api` dropped.
  - Empty docblock removal, including a file header and `{@inheritdoc}`.
  - Line, hash, block, inline and trailing comments, with the blank-line handling.
  - Every directive kind.
  - Shebang, attribute, strings and heredoc untouched.
  - Inline `/** @var */`.
  - The no-glue case.
  - Already-clean code unchanged.
- **Idempotency.** Each case also re-runs with `--dry-run` and expects exit 0.
- **Other tests.** A dry-run on a violation must exit non-zero and leave the file alone. One test
  pins the config wiring (registration and every Finder dir) and the `tools` path in PHPStan.

## Rejected alternatives

- **Fixer in `bin/` or `src/`:** see above.
- **Loading the shim's `vendor/autoload.php` in PHPStan or PHPUnit:** it prepends an autoloader
  with a different Symfony, so it would shadow the project's classes.
- **A PHPStan stub for `Tokens`:** see above.
- **In-process fixer unit tests (`Tokens::fromCode` + `fix()`):** same autoloader conflict, and they
  would depend on the phar's internals.
- **Relying on `no_extra_blank_lines` for blank-line runs:** `@PER-CS2x0` configures it with
  `tokens: ['use']` only, so `extra` blank lines would survive. The fixer manages the whitespace it
  touches itself.
- **Keeping the permanent PHPUnit attribute set in `rector.php`:** not needed. The fixer would now
  delete any new doc annotation anyway, and PHPUnit 10 still reads annotations, so a forgotten
  annotation silently stops working. See findings: a gate against that is a follow-up candidate.

## Uncertainties (please check)

1. **`@param-out` is kept, like `@param`.** It was not on the issue's list. It is a typed tag
   PHPStan uses for the by-ref `$lower` in `HeaderNameNormalizer::normalize()`; dropping it would
   lose type information.
2. **`@extends`, `@implements`, `@use`, `@mixin`, `@method` and `@property*` are removed, as the
   issue says.** None occur in the repo today. CONTRIBUTING tells contributors to use the
   `@phpstan-` variants, which survive. If you would rather keep the plain tags, adding them to
   `TYPED_TAGS`/verbatim is a one-line change.
3. **`@codeCoverageIgnore*` in a docblock is kept bare.** The issue listed it only for line
   comments, but deleting it from a docblock would change coverage.
4. **`@deprecated` loses its text.** The text includes the replacement API and the removal version
   (`Utils::*`, `Request::withHeader()`). IDE users no longer see "use X instead". `UPGRADE.md`
   still carries it.
5. **`@author Kevin Bond` on `JitterTrigger` was removed.** If the class was derived from
   symfony/scheduler code, check that attribution is not required. Symfony's MIT notice is the
   LICENSE copyright line, not `@author`, but please confirm.
6. **The root configs were added to the Finder** (`.php-cs-fixer.dist.php`, `rector.php`). Their
   rule-explanation comments are gone. Revert the `append` if you want them out of scope.
7. **Test count:** the attribute migration leaves the `--list-tests` output identical (2917 lines).
   After that, the only changes to the list are:
   - the two `RequestTest::test*DocumentsXForwardedSecurityWarning` tests replaced by
     `testSecurityDocsWarnAboutForwardedHeaderReInjection`;
   - the new `NoNarrativeCommentsFixerTest` entries.

   `composer test` runs 2932 tests.
8. **Docblocks whose only content was a description, before an `@internal`-like test affordance**
   (`reset()` methods), now read just `@internal`. "Production code must not call this" is gone.
