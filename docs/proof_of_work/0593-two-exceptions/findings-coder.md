# Findings — coder — issue #593

## What I found along the way

### Biggest problem faced

**Deciding per-class between Option A (wire up) and Option B (delete), then
finding that one of the two had no honest throw site.**

The issue framed both classes as "a signal of a missing throw", and for
`InvalidCacheDirectoryException` that was exactly right: `Runner::applyWorkermanConfig()`
had two `throw new \RuntimeException(...)` sites (`src/Runner.php:183` and
`:191`) for uncreatable runtime directories — real error paths escaping the
hierarchy. Wiring that class up was a clean one-line-per-site change.

For `ConfigurationValidationException` the "missing throw" reading was
tempting but wrong on inspection. `ConfigLoader::warmUp()` throws
`\LogicException` for missing sections (a programming error, not user-config
validation), and the Symfony Config constraints in
`ConfigurationTreeBuilder` are enforced by Symfony's own `Processor` /
`DefinitionConfigurator`, which throws
`Symfony\Component\Config\Definition\Exception\InvalidConfigurationException`
— the bundle never calls a `Processor` itself (`WorkermanBundle` extends
`AbstractBundle`; Symfony's bundle system does the processing before
`loadExtension` sees the config). There is no bundle throw site to wrap.
Wiring it up would have meant adding a `try/catch` purely to manufacture a
use, which the issue explicitly says is not the goal ("preferred **if the
error paths exist**"). Deleting was the honest call, but it required
correcting the README count *and* the living UPGRADE.md 0.12 table (which
had always been inaccurate in listing this class as a replacement for
`\InvalidArgumentException`).

A secondary obstacle: my first `InvalidCacheDirectoryException` had a
constructor that only delegated to `parent::__construct($message)`, and
Rector's `RemoveParentDelegatingConstructorRector` flagged it. Resolved by
dropping the constructor entirely — `\RuntimeException::__construct(string
$message = "", ...)` is inherited and does the job. Rector-clean and
simpler.

### Obstacles / surprises

- **The two `mkdir` conditions in `Runner::applyWorkermanConfig` have
  different parenthesization.** The PID-dir check wraps the `mkdir` call in
  extra parens (`(!mkdir(...) && !is_dir(...))`), the log/stdout check does
  not (`!mkdir(...) && !is_dir(...)`). Both are functionally identical
  (`&&` short-circuits either way), but the inconsistency tripped my first
  `edit` attempt (the `oldString` didn't match because I assumed they were
  identical). Not a bug, just noise. `src/Runner.php:182` vs `:190`.

- **`tests/RunnerTest.php::testApplyWorkermanConfigThrowsOnMkdirFailure`
  uses `set_error_handler` to suppress PHP's `mkdir(): Not a directory`
  warning.** When I added the parallel hierarchy-assertion test I had to
  reuse the same suppression pattern, and I had to be careful that the
  `fn()` arrow-function style matched the project's cs-fixer rule
  (`fn()` with no space before paren, not `fn ()`) — cs-fixer caught it on
  the first dry-run.

- **`expectException()` called twice in PHPUnit overwrites the class
  constraint** — only the last call survives. I initially stacked
  `expectException(InvalidCacheDirectoryException::class)` and
  `expectException(\RuntimeException::class)`; the second would have
  shadowed the first. Fixed by using a single `expectException` for the
  concrete type in the existing test, and a manual try/catch + three
  `assertInstanceOf` calls in the new dedicated test.

## Discovered bugs / places to improve (including out-of-scope)

1. **`src/Runner.php:25-31` — `Runner` constructor throws a bare
   `\InvalidArgumentException` for a non-positive `cacheWarmupTimeout`,
   outside the bundle's exception hierarchy.** This is the exact pattern
   #593 is about: a real validation error escaping the hierarchy. There is
   no existing `ValidationException` subclass for config-value validation
   (the deleted `ConfigurationValidationException` was the closest
   candidate, but it was never thrown and has the wrong semantics — it
   implied *Symfony config-tree* validation, not constructor-argument
   validation). Suggested fix: either accept this as a
   programming-error path (a `\InvalidArgumentException` is defensible for
   a bad constructor arg) or introduce a small
   `InvalidConfigurationException extends ValidationException` for
   constructor/argument validation and use it here and at other bare
   `\InvalidArgumentException` sites. Out of scope for #593; flagging for a
   follow-up.

2. **`src/Runner.php:85, 124, 131, 136, 144, 148, 154` — `warmUpCache()`
   throws bare `\RuntimeException` at 7 sites (fork failure, wait failure,
   timeout, unexpected status, SIGTERM, unexpected signal, non-zero exit).**
   These are all cache-warmup-process failures, not "cache directory
   unusable" — so `InvalidCacheDirectoryException` does not fit them. They
   sit outside the hierarchy the README claims is complete. The
   `@throws \RuntimeException` docblock at `src/Runner.php:75` documents
   them, but a caller catching `WorkermanExceptionInterface` misses all 7.
   Suggested fix: a dedicated `CacheWarmupException extends KernelException`
   (or a small hierarchy: `CacheWarmupTimeoutException`,
   `CacheWarmupFailureException`) would bring them inside. Out of scope for
   #593 (the issue scoped the unused-class cleanup, not a full hierarchy
   audit); flagging for a follow-up. This is also the reason I softened the
   README to "for the bundle's error cases" rather than "for every error
   case".

3. **`src/Runner.php:182` vs `:190` — inconsistent parenthesization of the
   `mkdir` guard** (one wraps `mkdir` in extra parens, the other does not).
   Cosmetic; the behaviour is identical. Suggested fix: pick one form and
   apply it to both for readability. Trivial, could be folded into any
   future touch of this method.

4. **`UPGRADE.md` "Upgrading to 0.12" table historically listed
   `ConfigurationValidationException` as a replacement for
   `\InvalidArgumentException`** (`UPGRADE.md:488` before this PR). This was
   always inaccurate — the class was never thrown, so no
   `\InvalidArgumentException` site was ever replaced by it. I corrected the
   living UPGRADE.md table in this PR (removed the class from the table and
   the hierarchy tree). The released `## [0.12.0]` CHANGELOG entry still
   names it (`CHANGELOG.md:1507`); I left that as an immutable historical
   record. If a reviewer wants the historical entry cleaned too, it is a
   one-line edit, but I recommend against rewriting released changelog
   entries.

5. **`docs/helpers/faq.md` is over its 300-line budget** (404 lines,
   reported by `kb-lint.php` as a warning during this cycle). Pre-existing,
   unrelated to #593, and outside my scope (only the retro step writes to
   `docs/helpers/`). Flagging because `composer lint` surfaces it and a
   future cycle should address it via promotion/stale pruning per the KB
   decay rules.

## Candidate KB entries (proposed, not written)

- **Title:** "Exception-hierarchy usage is gated, not just counted"
  **Tags:** `lint`, `ci`, `architecture`
  **Trigger:** "adding or removing a class in src/Exception/, or touching
  the exception-hierarchy claim in README.md"
  **Paragraph:** `bin/check-exception-usage.php` (added by #593, wired into
  `composer lint`) verifies every type in `src/Exception/` is referenced by
  at least one PHP file outside its own definition. The README advertises
  the hierarchy's size as a feature, so an unused member is dead code that
  inflates a selling point. Add new exception classes only when a real throw
  site exists; the gate will fail the build otherwise. Editing the README
  count requires updating both the feature bullet and the "Code quality / DX"
  line, and the count is `final classes + abstract bases` (excluding the two
  marker interfaces).

- **Title:** "Released CHANGELOG entries are immutable; UPGRADE.md is living"
  **Tags:** `docs`, `upgrade`, `bc`
  **Trigger:** "deleting a class or changing a thrown type that a past
  released CHANGELOG version mentions"
  **Paragraph:** When removing a class that a released `## [x.y.z]`
  CHANGELOG entry names, record the removal in a new `[Unreleased]` section
  rather than editing the released entry — released changelogs are a
  historical ledger. `UPGRADE.md`, by contrast, is living guidance: correct
  its past-version sections when they become inaccurate (e.g. a class listed
  as a replacement that was never actually thrown), so an upgrader gets
  accurate migration steps. Discovered in #593, where
  `ConfigurationValidationException` was deleted: the 0.12 CHANGELOG entry
  kept the name (history) while the 0.12 UPGRADE table was corrected
  (guidance).

---

## Round 2 — fixing review round 1 findings

### Biggest problem faced

**Confirming that the F-01 regex bug was real and that the tokenizer fix
actually solves it, not just moves the problem.**

The review's F-01 finding was precise: the regex
`\b(?:interface|abstract\s+class|final\s+class|class)\s+(\w+)/` captures
`for` from the docblock "Marker interface for exceptions" in both interface
files. I verified this directly:

```
$ php -r "preg_match('/\b(?:interface|abstract\s+class|final\s+class|class)\s+(\w+)/', file_get_contents('src/Exception/ClientInputExceptionInterface.php'), \$m); echo \$m[1];"
for
```

So the script was reporting "22 types OK" but two of those 22 "types" were
actually the word `for` — a PHP keyword that matches in virtually every PHP
file. The interfaces `ClientInputExceptionInterface` and
`WorkermanExceptionInterface` were never checked at all. An unused interface
would have passed silently.

The tokenizer fix (`token_get_all()` → find `T_INTERFACE`/`T_CLASS` →
extract next `T_STRING`) is the textbook solution, but I needed to confirm
it handles the real-world modifiers in the tree (`abstract class`, `final
class`). It does: modifiers like `abstract`, `final`, `readonly` are
separate tokens that *precede* `T_CLASS`, so the scanner sees `T_CLASS`
regardless of which modifiers are present. The count stays 22 (same as
before), but now all 22 are real type names, not `for`.

I also confirmed the regression test catches the bug: reverting to the old
regex causes `testTheWordInterfaceInADocblockIsNotMistakenForADeclaration`
to fail (the script reports `for` instead of `UnusedMarkerInterface`).

### Obstacles / surprises

- **`getenv()` returns `array<string|false>` in PHP's type system, but
  `proc_open`'s env parameter expects `array<string, string>`.** Passing
  `[...getenv(), ...$env]` directly works at runtime (the `false` values
  are just skipped), but PHPStan at the project's level could flag it. In
  this case PHPStan passed clean — the `proc_open` parameter is typed
  loosely enough. If it had flagged, I would have filtered out `false`
  values with `array_filter(getenv(), fn ($v) => $v !== false)`.

- **The UPGRADE.md hierarchy tree has a structural ambiguity with
  `FileUploadValidationException`.** It extends `ValidationException` AND
  implements `ClientInputExceptionInterface`. In a tree, a node can only
  have one parent. I chose to show it under both (once under
  `ClientInputExceptionInterface`, once under `ValidationException`) since
  the tree is a human-readable guide, not a strict single-inheritance
  diagram. This is the same convention the pre-existing tree used for
  `WorkermanExceptionInterface` (shown as the root even though some classes
  implement it rather than extend a class that does).

### Discovered bugs / places to improve (including out-of-scope)

6. **The F-01 regex bug means every prior CI run of
   `bin/check-exception-usage.php` was partially vacuous.** Before round 1's
   fix (which is what round 2 fixes), the two marker interfaces
   (`ClientInputExceptionInterface`, `WorkermanExceptionInterface`) were
   never actually checked for references — the regex captured `for` from
   their docblocks and `for` matches everywhere. This means the "22 types
   OK" output was misleading: only 20 were genuinely verified. Now fixed by
   the tokenizer approach. No residual impact — both interfaces are
   genuinely referenced in the codebase.

7. **`UPGRADE.md` hierarchy tree was missing 4 types (pre-existing, F-04).**
   `MalformedRequestException`, `SfxExtractionException`,
   `UnsupportedListenSchemeException`, and `ClientInputExceptionInterface`
   were absent from the "Upgrading to 0.12" tree. The migration table was
   also missing `MalformedRequestException` (from the
   `\InvalidArgumentException` row) and `SfxExtractionException` +
   `UnsupportedListenSchemeException` (from the `\RuntimeException` row).
   Fixed in this round. Suggested follow-up: a CI gate that verifies the
   UPGRADE.md tree matches `src/Exception/` would prevent future drift, but
   that is out of scope for #593.

### F-05 confirmation

The released `## [0.12.0]` CHANGELOG entry at `CHANGELOG.md:1507` still
names the deleted `ConfigurationValidationException`. This is confirmed and
intentionally left unedited — released changelog entries are an immutable
historical ledger. The `[Unreleased]` section correctly records the removal.
No action needed.
