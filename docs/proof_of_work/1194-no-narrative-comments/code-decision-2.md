# Code decision — round 2 (#1194)

Fixes for review round 1 (R1-F1, F2, F5, F6, F7, F8). F3 (licensing) and F4 (knowledge base)
are out of scope for the coder.

## F2: forgotten test annotations must not vanish

- **(a) Rector converts PHPUnit annotations.** `rector.php` now keeps
  `PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES`. `bin/lint.sh` runs Rector before php-cs-fixer in
  `lint-fix`, so a PHPUnit annotation is converted before the comment rule sees it. The
  `composer lint` Rector dry-run stays clean on the current tree.
- **(b) The fixer leaves test annotations alone.** If a docblock contains any PHPUnit or PHPBench
  annotation tag, the fixer leaves the *whole* docblock untouched, including its summary text.
  - The tag list lives in `tools/php-cs-fixer/TestAnnotationTags.php`, a plain class with no
    php-cs-fixer dependency, so the fixer and the scan test share it.
  - Matching is case-sensitive, on `@tag` after line start, whitespace or `*`, and never followed
    by a word character or `-` (`@test` matches; `@testing` and `user@group.example` do not).
  - I chose "leave the docblock untouched" over "keep only the annotation tags": partially
    stripped annotations would still pass `lint`, and the scan gate is what forces the conversion.
- **(c) A scan test finds leftovers.** `tests/Tools/NoTestDocAnnotationsTest.php` scans the
  `T_DOC_COMMENT` tokens of every tracked PHP file (all linted dirs, not only `tests/` and
  `benchmarks/`, so `src/` cannot use the escape hatch). It fails, listing file and line, while any
  such annotation remains. Strings and heredocs are not doc comments, so the fixer test fixtures
  don't trip it.
  - It also pins that `rector.php` keeps the PHPUnit set.
- **Why both (a) and (b)/(c):** Rector has no PHPBench set, and on PHPUnit 10 it skips `@covers`.
  Those must be converted by hand, and the scan test makes that impossible to forget.

## F7: no allowed tag may be dropped

- A one-line docblock is split before every `@tag` (`\h+(?=@[A-Za-z])`), so
  `/** @param int $a x @param int $b y */` becomes `/** @param int $a @param int $b */`.
- A one-line docblock stays one line, with the kept tags joined by a space. Turning it into a
  multi-line block would need the indentation of the surrounding code, which the docblock token
  does not carry.
- **Scope:** this splitting is for one-liners only. In multi-line docblocks a tag must start its
  line, as before. Splitting mid-line there would break `@phpstan-type` shapes, which contain
  `// @deprecated …` lines.
- **New test:** `testNoAllowedTagIsEverDropped` runs 20 allowed tag forms through three layouts
  each and asserts every tag is still there:
  - multi-line with a summary and a description;
  - a one-liner with a second tag;
  - a block of bare tags.

## F1: removing a comment must not glue tokens

- Whether two tokens can touch is now checked with `token_get_all()`: `joinsSafely()` tokenises
  `left . right` and requires exactly those two tokens back.
- **Applied wherever the fixer drops the whitespace next to a removed inline comment:**
  - prev whitespace is dropped only if the next token is whitespace, or the tokens on both sides
    join safely;
  - next whitespace is dropped only if the previous token is whitespace, or the neighbours join
    safely;
  - with whitespace on neither side, the comment becomes a single space unless the neighbours
    join safely.
- Results: `$a-/* x */-$b` becomes `$a- -$b`, `$a./**/5` becomes `$a. 5`, `return/* x */1`
  becomes `return 1`, and `foo(/* x */ 1` still becomes `foo(1`.
- **New check in every fixer test case:** the non-comment, non-whitespace token stream of the
  output must equal the input's. This catches the whole class of bug, not just the listed inputs.

## F5: directives must start the comment

- The new regex is
  `~^(?://|#|/\*+)[\s*]*(?:@phpstan-ignore|@codeCoverageIgnore|phpcs:|@psalm-suppress)~i`.
- A comment that only mentions a directive mid-sentence is now removed. Test case:
  `directives-must-start-the-comment`.
- A directive followed by a reason (`// @phpstan-ignore id (reason)`) is kept whole. That is
  PHPStan's own syntax.

## F6: unparsable type — fail loudly (chosen) instead of keeping it verbatim

- When a typed tag's type cannot be parsed, the fixer throws `UnexpectedValueException`. The
  message names the tag line and suggests the fix: correct the type, or use the `@phpstan-` form,
  which is kept verbatim.
- **What the user sees:** php-cs-fixer reports the file under "Files that were not fixed due to
  errors" and exits 64, so `composer lint` and `lint-fix` fail.
  - With `-v`, which `bin/lint.sh` uses, the output names the file and says to re-run with
    `-vvv`. With `-vv` and above, the message itself is printed.
- **Why not keep it verbatim:** that is exactly the silent escape the review flagged.
- **Why not keep only the first line:** that hides the problem and may still keep a description.
- **Risk:** a valid but exotic type that php-cs-fixer's `TypeExpression::REGEX_TYPES` cannot
  parse blocks lint. The `@phpstan-` escape route covers it. No such type exists in the repo:
  `lint-fix` over the whole tree is unchanged.
- **Test:** `testUnparsableTypeFailsLoudly`, in dry-run and fix mode. It checks a non-zero exit,
  the message, and that the file is left unchanged.

## F8: the autoloader no longer hard-codes `vendor/`

- The loader is now lazy and runs only for `PhpCsFixer\` classes nobody has loaded. Inside
  php-cs-fixer itself, the phar's own loader always wins, so this path never runs there.
- **Order used to find the phar:**
  1. `Composer\InstalledVersions::getInstallPath('php-cs-fixer/shim')`, when the project's
     Composer runtime is loaded (PHPStan, Rector);
  2. `COMPOSER_VENDOR_DIR`;
  3. `config.vendor-dir` in `composer.json`;
  4. `vendor`.
- **When the phar is missing:** a `RuntimeException` names the expected path and says
  "run composer install".
- **Test:** `tests/Tools/PhpCsFixerAutoloadTest.php` checks both the resolving path and the error.

## Tool output

`composer lint-fix` after these changes left every already-cleaned file untouched. So there is no
separate tool-output commit: the stricter directive regex and the one-liner split found nothing new
in the tree.

## Uncertainties

1. **The one-liner split can create a group from narrative text.** In
   `/** @var int counter for @internal use */`, the text after `@internal` becomes a bare
   `@internal`. That is a tag the author wrote, so I accept it. No instance exists in the repo.
2. **A legacy docblock now keeps its narrative while it holds a test annotation.** The scan test
   fails until it is converted. This is intentional: losing the annotation is worse than keeping
   text for one review cycle.
3. **`@uses`, `@small`, `@medium`, `@large`, `@ticket` and `@test` are in the list.** They are also
   phpDocumentor or generic words. In `src/`, a docblock using them would be left untouched and
   the scan test would flag it. That is acceptable, since the tags themselves carry no type.
4. **A process-tooling test conflicted with the review file.** `BinDirectoryTest::testNoTrackedDocMentionsTheRemovedProcessTooling`
   forbids every tracked Markdown file from naming the workflow scratch files. Line 46 of the
   committed round-1 review named one, so `composer test` failed. I reworded that single line
   ("the review findings file"), with no change in meaning, in its own commit. See the coder findings.
