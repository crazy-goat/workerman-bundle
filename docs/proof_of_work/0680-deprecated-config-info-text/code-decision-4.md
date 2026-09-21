# Code decision — #680, round 4 (full-suite GC interaction)

Rounds 1-3 ran targeted tests (`ConfigurationTreeBuilderTest`) plus full lint.
Step 7's full `composer test` exposed an interaction the targeted runs could
not: with the two new guard tests present the suite failed deterministically at
`RebootStrategyTest::testMemoryRebootStrategyGcCollectsCyclesWhenTriggered`.

## Diagnosis

- `git show master:tests/DependencyInjection/ConfigurationTreeBuilderTest.php`
  restored over the branch → full suite passes (2759 tests).
- Replacing the two new tests with two trivial `assertTrue(true)` tests → full
  suite passes too.
- So the trigger is the *content* of the new tests: they build Symfony config
  trees (with cyclic parent/child node references) via
  `legacyStaticFileNodeInfos()`, and the resulting garbage left in the GC root
  buffer shifts when PHP's automatic GC fires during `RebootStrategyTest`'s
  10 000-cycle construction. `RebootStrategyTest` then sees
  `gc_collect_cycles()` return `0` and fails.
- This is a latent fragility in `RebootStrategyTest` (it assumes the root
  buffer state before its own `gc_collect_cycles()`), not a defect in the
  `info()` change. It needs a full-suite run to surface.

## What changed

Added to `ConfigurationTreeBuilderTest`:

```php
protected function tearDown(): void
{
    gc_collect_cycles();
}
```

with a docblock explaining why. The file we were already editing now cleans up
the cyclic config-tree garbage it creates, so it cannot perturb GC-sensitive
tests that run later in the suite.

## Alternatives rejected

1. **Fix it in `RebootStrategyTest` (e.g. `gc_disable()`/`gc_enable()` around
   the 10 000-cycle test).** This is the more durable fix — the test is
   order-dependent regardless of who triggers it — but it is unrelated to #680
   and would broaden the PR into another test's logic. Filed as a candidate
   issue at step 14 instead; the `tearDown` here removes the only known trigger.
2. **Drop the new guard tests.** They close the F4/F5/F6 findings and are the
   point of the round; unacceptable.
3. **Leave the suite red and rely on CI.** Step 7 requires local green before
   the PR, and the failure was deterministic, not flaky.

## Uncertainties

- `tearDown()` does not call `parent::tearDown()`; `PHPUnit\Framework\TestCase`'s
  implementation is empty, so this is a no-op difference.
- The `RebootStrategyTest` order-dependence remains latent; the candidate issue
  records it so it is not lost.
