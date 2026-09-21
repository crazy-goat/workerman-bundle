# Findings — issue #680

## Obstacles / surprises

- None blocking. The change is a pure documentation-string change on a Symfony
  config node; all 13 tests in
  `tests/DependencyInjection/ConfigurationTreeBuilderTest.php` pass unchanged,
  confirming no test pins the old `info()` text.
- `grep -R "Should current worker" tests/` returns no hits, so there is no
  `config:dump-reference` snapshot/reference test in the repo. Worth noting:
  the issue asked to check for one; it does not exist.

## Bugs / weak spots noticed (including out of scope)

1. **`info()` and `setDeprecated()` can silently drift apart**
   `src/DependencyInjection/ConfigurationTreeBuilder.php:135-144`. This issue
   exists precisely because nothing links the two, and no test asserts either
   text. Suggested fix (optional, low priority): a small test that walks the
   processed tree for the three legacy static-file nodes and asserts each
   `info()` contains "deprecat" (case-insensitive). This would prevent a future
   node from losing its deprecation mention without pinning exact wording.

2. **`serve_files` and `root_dir` are independently settable with no validation**
   `src/DependencyInjection/ConfigurationTreeBuilder.php:134-144`. `serve_files:
   true` with `root_dir: null` (the default) is accepted by the tree, and
   whether that is a meaningful combination is left to the runtime. Out of
   scope for a string-only change, but a `beforeNormalization`/validation rule
   (or an `info()` note) could warn that the two must be set together. Verify
   the runtime's actual behavior before changing anything — it may treat
   `serve_files: true`/`root_dir: null` as an error already.

3. **Long `info()` lines have no enforced width**
   `src/DependencyInjection/ConfigurationTreeBuilder.php` throughout (e.g.
   lines 90, 76, 296). php-cs-fixer does not constrain string length, so the
   file already carries 200+ char lines. New strings follow the existing style;
   no action needed, but if a max-line-length rule is ever added this file will
   need a large reformat.
