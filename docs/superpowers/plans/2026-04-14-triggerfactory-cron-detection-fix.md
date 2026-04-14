# TriggerFactory Cron Expression Detection Fix - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix fragile cron expression detection in TriggerFactory by using CronExpression::isValidExpression()

**Architecture:** Replace fragile heuristic (5 parts + contains '*') with library validation method. The library already handles all valid cron formats including aliases.

**Tech Stack:** PHP 8.2, cron-expression library (dragonmantank/cron-expression), PHPUnit

---

## Task 1: Update TriggerFactory.php

**Files:**
- Modify: `src/Scheduler/Trigger/TriggerFactory.php:5-7`
- Modify: `src/Scheduler/Trigger/TriggerFactory.php:37-42`

- [ ] **Step 1: Add CronExpression import**

Add after line 5 (namespace line):
```php
use Cron\CronExpression;
```

- [ ] **Step 2: Replace fragile cron detection with library validation**

Replace lines 39-40:
```php
is_string($expression) && count(explode(' ', $expression)) === 5 && str_contains($expression, '*'),
is_string($expression) && str_starts_with($expression, '@') => new CronExpressionTrigger($expression),
```

With:
```php
is_string($expression) && CronExpression::isValidExpression($expression) => new CronExpressionTrigger($expression),
```

- [ ] **Step 3: Run tests to verify changes**

Run: `./vendor/bin/phpunit tests/TriggerFactoryTest.php -v`
Expected: All tests pass (some may fail - see Task 2)

- [ ] **Step 4: Commit**

```bash
git add src/Scheduler/Trigger/TriggerFactory.php
git commit -m "fix: use CronExpression::isValidExpression for robust cron detection"
```

---

## Task 2: Update TriggerFactoryTest.php

**Files:**
- Modify: `tests/TriggerFactoryTest.php:114-128`
- Modify: `tests/TriggerFactoryTest.php:131-137`

- [ ] **Step 1: Add test case for cron without asterisks**

Add to `cronExpressionProvider` array (after line 127):
```php
'daily at midnight on Monday (no asterisks)' => ['0 0 1 1 1'],
```

- [ ] **Step 2: Update testFivePartNonCronExpressionThrowsException**

The test `'1 2 3 4 5'` previously expected exception, but `CronExpression::isValidExpression('1 2 3 4 5')` returns `true` (it's valid - means 1:02:03 on 4th day of 5th month).

Replace `testFivePartNonCronExpressionThrowsException` with a test for a truly invalid expression:
```php
public function testFivePartNonCronExpressionThrowsException(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid interval');

    TriggerFactory::create('1 2 3 4 5 6');  // 6 parts - invalid
}
```

- [ ] **Step 3: Run tests to verify all pass**

Run: `./vendor/bin/phpunit tests/TriggerFactoryTest.php -v`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add tests/TriggerFactoryTest.php
git commit -m "test: add cron detection test cases and fix obsolete exception test"
```

---

## Task 3: Final verification

**Files:**
- Run: Full test suite

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 2: Run static analysis if available**

Run: `./vendor/bin/phpstan analyse src/Scheduler/Trigger/TriggerFactory.php --level=max 2>/dev/null || echo "phpstan not configured"`

---

## Spec Coverage Check

- [x] Replace fragile heuristic - Task 1
- [x] Add CronExpression import - Task 1
- [x] Test for cron without asterisks (0 0 1 1 1) - Task 2
- [x] Remove obsolete test - Task 2
- [x] Verify no regressions - Task 3
