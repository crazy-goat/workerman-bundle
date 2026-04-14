# TriggerFactory Cron Expression Detection Fix

**Date:** 2026-04-14
**Issue:** #34
**Status:** Approved

## Problem

`TriggerFactory::create()` uses a fragile heuristic to detect cron expressions:

```php
is_string($expression) && count(explode(' ', $expression)) === 5 && str_contains($expression, '*')
```

This fails for valid cron expressions without asterisks, such as `0 0 1 1 1` (January 1st at midnight, on Monday).

## Solution

Replace the fragile heuristic with `CronExpression::isValidExpression()` from the `dragonmantank/cron-expression` library, which is already a dependency.

### Changes

**File:** `src/Scheduler/Trigger/TriggerFactory.php`

1. Add import: `use Cron\CronExpression;`
2. Replace lines 39-40 with single condition:
   ```php
   is_string($expression) && CronExpression::isValidExpression($expression) => new CronExpressionTrigger($expression),
   ```

The `str_starts_with($expression, '@')` check is no longer needed because `CronExpression::isValidExpression()` handles alias expressions (@daily, @hourly, etc.).

### Test Updates

**File:** `tests/TriggerFactoryTest.php`

1. Add test case `0 0 1 1 1` to `cronExpressionProvider`
2. Update `testFivePartNonCronExpressionThrowsException` - remove or adjust since `1 2 3 4 5` is now considered a valid cron expression by the library (which is correct - it's a valid cron expression for 1:02:03 on April 5th)

## Verification

Run existing tests to ensure no regressions:
```bash
./vendor/bin/phpunit tests/TriggerFactoryTest.php
```
