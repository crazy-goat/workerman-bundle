# Findings — Review

## Round 1

### R1-F1 — HIGH — Arrow functions missing return type declarations

- **File:** `tests/Worker/SchedulerWorkerTest.php:700, 739`
- **What:** The two new arrow functions `fn(\DateTimeImmutable $now) => $now->modify('+1 second'))` lacked explicit return type declarations. The project's Rector config (`AddArrowFunctionReturnTypeRector`) requires them, and `RectorConfigTest::testRectorDryRunPasses` enforces a clean dry-run — the untyped arrows caused 2 test failures.
- **Severity:** HIGH
- **Automated check:** `RectorConfigTest` / `composer lint` (rector dry-run). This is the check that caught it.
- **Status:** **FIXED** — added `: \DateTimeImmutable` return type to both closures. `RectorConfigTest` passes (32/32 green).
