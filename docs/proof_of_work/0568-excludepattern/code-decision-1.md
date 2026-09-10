# Code decision 1 — issue #568

`ExcludePattern::matches()` performed 4 `ini_set()` calls per invocation
(2 to raise `pcre.backtrack_limit` / `pcre.recursion_limit` to the bounded
ReDoS ceilings, 2 to restore them). The limits are constants and identical
for every call, so the save/restore was pure churn. This moves the
establish/restore to once per filtering pass and leaves `matches()` as a
bare `@preg_match` + `false`-result safety net.

## Approach

A new `PcreLimitGuard` (`src/Phar/PcreLimitGuard.php`) owns the save/restore.
`PharBuilder::build()` instantiates it, calls `enter()` before
`buildFromIterator()`, and calls `exit()` in a `finally` that wraps
`buildFromIterator()` + `setStub()` + `stopBuffering()`:

```php
$limitGuard = new PcreLimitGuard();
$limitGuard->enter();
try {
    $phar->buildFromIterator($filtered, $this->projectDir);
    $phar->setStub($this->generateStub($buildConfig, $pharFilename));
    $phar->stopBuffering();
} finally {
    $limitGuard->exit();
}
```

`PcreLimitGuard`:

- `enter()` captures the prior ini values via `ini_set(...)` (which returns
  the old value as string|false) and stores them; sets `$active = true`.
  Idempotent: a second `enter()` without `exit()` is a no-op (does not
  overwrite the captured priors, so the first `exit()` still restores the
  true originals).
- `exit()` restores the captured values (guarding with `is_string()` so a
  value that was originally disabled/unchanged is left alone, matching the
  original code's semantics), then clears state. Idempotent and safe to call
  without a prior `enter()` — important because it sits in a `finally` that
  could run if `enter()` itself threw (it cannot today, but the property
  must hold for future callers).

`ExcludePattern::matches()` is now:

```php
public function matches(string $path): bool
{
    $result = @preg_match($this->regex, $path);
    if ($result === false) {
        return false; // tripped limit => no match, build does not hang (#334)
    }
    return $result === 1;
}
```

The `@` suppression and `false` check stay — that is the actual ReDoS guard
from #334 (a tripped backtrack limit becomes "no match" instead of a hang).
Only the ini save/restore moved out.

The `BACKTRACK_LIMIT` / `RECURSION_LIMIT` constants on `ExcludePattern` were
widened from `private` to `public` so `PcreLimitGuard` references a single
source of truth (`ExcludePattern::BACKTRACK_LIMIT`) instead of duplicating
the magic numbers. The construction-time probe compile in the constructor
keeps its own local `ini_set`/restore around `preg_match($regex, $probe)` —
that runs once per pattern (not on any hot path) and is part of the
"construction-time validation unchanged" requirement, so it was left
exactly as-is.

## Why a guard object, not inline ini_set in build()

Two options the issue named:

1. **Inline `ini_set`/restore directly in `PharBuilder::build()`** around
   `buildFromIterator()`. Rejected: it spreads the save/restore logic
   (including the `is_string()` "was-disabled" guards) across the builder
   and makes it untestable in isolation. The restore-on-throw path would be
   a hand-written try/finally with four `ini_set` calls inline — exactly the
   logic that already lived in `matches()` and that we want to centralise.
2. **A scope guard object that `PharFileFilter` holds for its lifetime.**
   Rejected as written: `PharFileFilter` is `readonly` and constructed
   *before* the iterator, so entering the guard on the filter's lifetime
   would set the limits too early (before `buildFromIterator`) and the
   "per-pass" framing would be muddied. More importantly, entering lazily
   in `shouldInclude()` would reintroduce per-call ini churn.

The chosen shape — a standalone guard object owned by `build()` with an
explicit `enter()`/`exit()` around the pass — keeps the guard's lifecycle
exactly the filtering pass, is unit-testable (`PcreLimitGuardTest`), and
makes "restored even on throw" a one-line `finally`.

## PHP 8.2 compatibility

`composer.json` requires `^8.2`. `PcreLimitGuard` is a plain `final class`
(not `readonly`, not an anonymous readonly class) with no 8.3+ constructs.
The typed properties use `string|false|null` (union types, available since
8.0). Verified with `php -l` on PHP 8.5; the FAQ-037 trap (anonymous
`readonly class` is 8.3+) was deliberately avoided — `PcreLimitGuard` has
mutable state (`$active`, the captured priors) so `readonly` would be wrong
anyway.

## What stayed unchanged

- Construction-time `guardAgainstNestedUnboundedQuantifiers()` — untouched.
- Constructor probe compile (`@preg_match($regex, $probe)` + throw on
  `false`) with its own local ini_set/restore — untouched.
- `PharFileFilter` — untouched (still `readonly`, still calls
  `$pattern->matches()`).
- `PharBuilder::isExcluded()` — untouched (the issue explicitly notes it is
  ~1.2 ms and immaterial).

## Tests

- `tests/Phar/PcreLimitGuardTest.php` (new, 5 tests): enter sets the bounded
  limits; exit restores *prior* (not default) values; exit restores even
  when the protected block throws (the failing-pass acceptance criterion);
  exit is idempotent and safe without enter; enter is idempotent.
- `tests/Phar/PharBuilderTest.php::testBuildRestoresPcreLimitsWhenPassThrows`
  (new): an integration test that forces `buildFromIterator()` to throw by
  making a source file unreadable (chmod 000, skipped under root), then
  asserts the PCRE limits are restored to the pre-pass values (deliberately
  distorted first, so a "restore to PHP default" bug would be caught).
- `tests/Phar/ExcludePatternTest.php::testMatchesAppliesBacktrackLimitGuardPerCall`
  renamed to `testMatchesCompletesQuicklyUnderBacktrackLimit` with updated
  comments: the assertions (completes < 1 s, ini value unchanged after) still
  hold — outside a pass, PHP's defaults (1 000 000 / 100 000) match the
  guard's ceilings, so the ReDoS safety net is still effective and
  `matches()` leaves the ini value exactly as found. The construction-time
  ReDoS rejection tests (`testRejectsNestedUnboundedQuantifiersAt...`) are
  unmodified and pass.

All 182 `tests/Phar/` tests pass under `php -d phar.readonly=0 phpunit`
(including `PharReadOnlyGuardTest`). PHPStan, php-cs-fixer (dry-run), and
rector (dry-run) are clean on all touched files.

## Microbench

PHP 8.5.10, 500 000 reps, regex `#^src/skip-#` against `src/skip-me.php`.

Synthetic (isolates the ini churn):

| variant                              | us/op   |
|--------------------------------------|---------|
| bare `preg_match` (floor)            | 0.0188  |
| 4× `ini_set` overhead alone          | 0.1304  |
| OLD `matches()` (ini + preg_match)   | 0.1524  |
| NEW `matches()` (bare preg_match)    | 0.0237  |

- OLD/NEW ratio: **6.4×**
- NEW vs bare floor overhead: 0.0049 us/op (26%)
- The 4× ini_set overhead (0.130 us/op) is ~85% of the old function's cost.

Real `ExcludePattern` class (method call + `@` suppression + property read +
`false` compare included), inside the guard:

| variant                              | us/op   |
|--------------------------------------|---------|
| bare `preg_match` (floor)            | 0.0196  |
| NEW `matches()` under guard          | 0.0343  |

- Real-class overhead vs floor: 0.0147 us/op (75%) — the method-call and
  `@`-suppression cost that remains after removing the ini churn.
- vs the old real-class cost (~0.152 us/op from the synthetic, consistent
  with the issue's 0.213 us/op measured on a slower host): **~4.5× faster**
  end-to-end.

The issue's scope note puts the real-build impact at ~20 ms (10 131 files ×
a few patterns); this is a tidy-up, not a measured build-speed win. The
bench confirms the function went from 6.7× bare preg_match to ~1.7×.

## Uncertainties

- The `testBuildRestoresPcreLimitsWhenPassThrows` integration test relies on
  `buildFromIterator()` throwing on an unreadable file. On PHP 8.5 +
  phar.readonly=0 this produces `UnexpectedValueException` with
  "unreadable" in the message. If a future PHP/Phar version changes the
  message wording, the `assertStringContainsString('unreadable', ...)` could
  become brittle. Mitigation: the guard unit test
  (`testExitRestoresEvenWhenProtectedBlockThrows`) covers the restore-on-throw
  contract independently of Phar's error message, so the integration test is
  belt-and-suspenders. If it flakes, the unit test is the load-bearing one.
- The existing `testMatchesCompletesQuicklyUnderBacktrackLimit` relies on
  PHP's default `pcre.backtrack_limit` (1 000 000) equalling
  `ExcludePattern::BACKTRACK_LIMIT`. If a future PHP release raises the
  default above 1 000 000, the pathological `#.*foo.*#` against 2000 chars
  might backtrack longer before tripping — but the 1.0 s budget is generous
  and the pattern is only 2000 chars, so even an unbounded engine completes
  well under 1 s for that input size. Low risk.
