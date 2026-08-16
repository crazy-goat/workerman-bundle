# Findings — review (#630)

Round 1 findings. Each entry: `file:line | description | severity | what happened to it`.

## Round 1

- `src/DTO/RequestConverter.php:29` + `benchmarks/RequestConverterBench.php:29` | The 32-byte control-character mask is duplicated as two independent constants (`HEADER_VALUE_CONTROL_CHARS` and `CONTROL_CHAR_MASK`) with no link pinning them together; if the production reject set ever changes the benchmark silently tests a stale mask, invalidating its old-vs-new verdict. Both constants are currently identical and the production mask is transitively pinned by the 256-value behaviour test, so this is future-drift maintainability only — no production/security impact today. | nit | FIXED (round 1) — cross-reference comments added to both constants (`src/DTO/RequestConverter.php:26-31`, `benchmarks/RequestConverterBench.php:23-30`) AND a reflection-based constant-equality test added: `RequestConverterTest::testBenchmarkControlCharMaskMatchesProduction()` (tests/RequestConverterTest.php:333) pins the benchmark mask to the production mask. Commit: f5d0d64.
