# Code Decision 1 — issue #593: two never-thrown exception classes

## Summary

Two exception classes in `src/Exception/` were referenced nowhere outside
their own definitions. The README advertised the hierarchy's size (21) as a
feature, which made the unused members worse than ordinary dead code.

Per class, I picked:

- **`ConfigurationValidationException`** → **Option B (delete).**
- **`InvalidCacheDirectoryException`** → **Option A (wire up).**

## Why Option A for `InvalidCacheDirectoryException`

`Runner::applyWorkermanConfig()` had two `throw new \RuntimeException(...)`
sites (PID dir at line 183, log/stdout dir at line 191) that fire when
`mkdir()` cannot create a runtime directory. These are exactly the "cache
directory is unusable" condition the class was designed for, and they were
the only `\RuntimeException` throw sites in the bundle that sat outside the
hierarchy the README claims is complete. Replacing them with
`throw new InvalidCacheDirectoryException(...)` is a clean, honest fit.

The class extends `KernelException` → `WorkermanException` →
`\RuntimeException`, so the replacement is backward-compatible: callers
catching `\RuntimeException` are unaffected, and callers catching
`WorkermanExceptionInterface` now cover this path too. This matches the
low-risk assessment in the issue.

The existing test `testApplyWorkermanConfigThrowsOnMkdirFailure` already
triggers the real condition (a file placed at the directory path blocks
`mkdir()`). I updated it to assert `InvalidCacheDirectoryException` instead
of `\RuntimeException`, and added a dedicated test
(`testApplyWorkermanConfigMkdirFailureThrowsTypedExceptionInHierarchy`) that
catches the exception and asserts all three of: the concrete type,
`WorkermanExceptionInterface`, and `\RuntimeException` — satisfying the
acceptance criterion "asserts the type, not a test that constructs and
throws it directly". Both tests trigger the real mkdir-failure condition.

I initially gave the class a constructor `__construct(string $message)` that
only delegated to `parent::__construct($message)`. Rector's
`RemoveParentDelegatingConstructorRector` flagged it as removable, and
correctly: `KernelException` is abstract with no constructor, so
`\RuntimeException::__construct(string $message = "", ...)` is inherited and
`new InvalidCacheDirectoryException($msg)` works without an explicit
constructor. I removed it — the class is now an empty `final class extends
KernelException` with only a docblock, which is the cleanest form and
Rector-clean.

## Why Option B for `ConfigurationValidationException`

I audited every place the issue suggested a throw site might exist:

- `ConfigLoader::warmUp()` throws `\LogicException` for missing config
  sections — that is a **programming error** (the caller forgot to call a
  setter), not a **user-configuration validation failure**. Wrapping it in
  `ConfigurationValidationException` (which extends `ValidationException`
  → `\InvalidArgumentException`) would be semantically wrong: a
  `\LogicException` is not a kind of `\InvalidArgumentException`.
- `ConfigurationTreeBuilder` defines Symfony Config constraints
  (`->min()`, `->cannotBeEmpty()`, `->isRequired()`, …), but the
  validation is performed by **Symfony's** `Processor` /
  `DefinitionConfigurator` mechanism, which throws Symfony's own
  `Symfony\Component\Config\Definition\Exception\InvalidConfigurationException`
  (an `\InvalidArgumentException`). The bundle never calls a `Processor`
  itself — `WorkermanBundle` extends `AbstractBundle` and Symfony's bundle
  system does the processing. There is **no bundle throw site to wrap**.

Wiring this class up would mean adding a `try/catch` around Symfony's
processor purely to manufacture a use — that is not a "real code path", it
is a wrapper for its own sake. The issue explicitly says wiring is
"preferred **if the error paths exist**"; here they do not exist in bundle
code. Deleting is the honest choice, and it makes the README claim accurate.

## What I rejected

1. **Wiring `ConfigurationValidationException` by catching Symfony's
   `InvalidConfigurationException` in `WorkermanBundle::loadExtension()`.**
   Rejected: `loadExtension` receives already-processed config (the
   `Processor` runs before it in the bundle system), so the catch would
   never fire there. Catching it in `configure()`/`ConfigurationTreeBuilder`
   would mean wrapping Symfony's own config-dsl execution, which the bundle
   does not control and which already produces good, typed exceptions. It
   would be a layering-violating wrapper with no caller benefit.

2. **Editing the historical `## [0.12.0]` CHANGELOG entry to remove
   `ConfigurationValidationException` from the released-version record.**
   Rejected: 0.12.0 is a released version (tagged 2026-04-04). Editing a
   released changelog entry rewrites history and is an anti-pattern. The
   proper place to record the deletion is a new `[Unreleased]` `### Removed`
   entry, which I added. The historical entry remains a factual record of
   what 0.12.0 shipped (even though the class was never actually thrown —
   the entry was slightly inaccurate when written, but that is a property of
   the past release, not something to retroactively fix in a changelog). I
   **did** correct the *living* `UPGRADE.md` 0.12 section, since UPGRADE.md
   is current guidance (not a historical ledger) and listing a never-thrown
   class as a replacement for `\InvalidArgumentException` was always
   inaccurate guidance.

3. **Keeping both classes and just fixing the README count.** Rejected: the
   issue's primary acceptance criterion is that neither class "remains in
   the tree unused — each is either thrown from at least one real code path
   or deleted". Just fixing the count would leave the dead code.

## The CI gate (acceptance criterion: "ideally that script runs in CI")

The issue suggests "adding the same reference check to the lint job … an
unused `final class` in `src/Exception/` is trivially detectable". I
implemented this as `bin/check-exception-usage.php` (word-boundary grep
across `src/`, `tests/`, `e2e/`, `benchmarks/`, excluding each type's own
file), wired into `composer lint`, with `tests/ExceptionUsageLintTest.php`
driving it as a subprocess (passing case = real tree; failure cases =
synthetic unused/referenced/self-referential fixtures). This follows the
"prefer a gate over an entry" principle in `docs/helpers/README.md` and the
existing `bin/check-changelog.php` + `tests/ChangelogStructureTest.php`
pattern. The gate checks interfaces and abstract bases too — an unused base
is a dead branch of the hierarchy.

## Uncertainties

- **Should the gate cover only `final class` or also abstract bases /
  interfaces?** I chose to check all three (interface, abstract class, final
  class). An unused interface or abstract base is just as much dead
  hierarchy as an unused final class, and the README counts them, so the
  gate should keep them honest too. The current tree passes either way.

- **README wording "for the bundle's error cases"** (replacing "for every
  error case"). The issue asked to drop the "for every error case" claim
  unless it is true. It is not literally true (not every conceivable error
  has a dedicated type — e.g. fork failures still throw plain
  `\RuntimeException`), so I softened it to "for the bundle's error cases".
  A reviewer may prefer a different phrasing.

- **The `0.12.0` CHANGELOG entry still names `ConfigurationValidationException`.**
  I left it as a historical record. If the reviewer considers this a
  violation of "no reference survives anywhere", it can be edited too, but I
  believe released changelog entries should be immutable.
