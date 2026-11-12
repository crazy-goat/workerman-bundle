# Findings: #1198 (review)

| Round | File:line | What is wrong | Severity | What happened to it |
|---|---|---|---|---|
| 1 | `tests/LintScopeTest.php:32-53`, `CONTRIBUTING.md:324-326` | R1-1: the gate checks only `phpstan.neon.dist`. An inline `@phpstan-ignore complexity.functionLike` (verified to suppress the rule), a bare `@phpstan-ignore-next-line`, or an extra `includes:` file that overrides `cognitive_complexity` all bypass it. CONTRIBUTING says `LintScopeTest` "fails if either appears", which reads as covering the whole repository. | low | fixed in round 2 (coder): `tests/CognitiveComplexityGateTest.php` scans the comment tokens of the analysed PHP for `complexity.` ignores and for bare `-next-line` / `-line` ignores on declaration lines, asserts that the extension is the only include, and forbids a tracked `phpstan.neon` / `phpstan.dist.neon`. `tests/PollingMonitorWatcherTest.php:81` was fixed, and CONTRIBUTING now matches what is enforced. Review round 2: **fixed**, verified (`tests/CognitiveComplexityGateTest.php:31-38, 66-71, 147-167`; lint clean; provider cases :105-117). |
| 1 | `tests/LintScopeTest.php:42-43` | R1-2: the `function:` and `class:` limit regexes take the first such key anywhere in the file, not the keys inside the `cognitive_complexity:` block. | nit | fixed in round 2 (coder): the limits are read from the `parameters → cognitive_complexity` block by an indentation-block reader, which requires exactly the keys `function` and `class`. A decoy key above the block is now caught (mutation-checked). Review round 2: **fixed**, verified (`tests/CognitiveComplexityGateTest.php:43-52`). |
| 2 | `tests/CognitiveComplexityGateTest.php:57-64` | R2-1: config-level bypasses remain. An `ignoreErrors` message regex that avoids `complexity.` and `cognitive complexity` (e.g. `'#keep it under \d+#'`), or a `parameters.excludePaths` entry, silences the rule and passes every assertion. Pin `ignoreErrors` to an allowlist of the current messages and forbid `excludePaths`. | low | open |
| 2 | `tests/CognitiveComplexityGateTest.php:66-71, 267-276` | R2-2: `gitLsFiles()` returns `[]` when git fails (no repo, `safe.directory`), so `testNoOtherPhpstanConfigIsTracked` passes vacuously. Assert the config itself is listed first, or also check `file_exists`. | nit | open |
| 2 | `tests/PollingMonitorWatcherTest.php:80-83` | R2-3: the narrowed `@phpstan-ignore missingType.generics` could likely be replaced by `@param \ReflectionClass<object> $class`, removing the ignore. Optional. | nit | open |

### Round 2 resolution (maintainer)

- R2-1: fixed. `testTheConfigHasNoBaselineAndNoComplexityIgnore` forbids `excludePaths` and pins the `ignoreErrors` message list to the two reviewed entries; verified by appending a `'#keep it under#'` ignore and an `excludePaths` entry, each failing the test.
- R2-2: fixed. `testNoOtherPhpstanConfigIsTracked` first asserts `git ls-files` lists `phpstan.neon.dist`, so a failing git cannot make the check vacuous.
- R2-3: not done (optional nit).

## Stage 2 (limit 40): review round 3

| Round | File:line | What is wrong | Severity | What happened to it |
|---|---|---|---|---|
| 3 | `tests/ServerWorkerTest.php:1251-1278` | R3-1: the `null`-context half of `testSweeperLeavesConnectionsWithoutContextOrLastActivityOpen` does not kill removal of the `instanceof \stdClass` guard in `ServerWorker::sweepConnection()` (`src/Worker/ServerWorker.php:203-205`). With a `null` context and an empty send buffer, the `is_int($lastActivity)` guard returns first. code-decision-3 says the skip is now checked, but the test only executes it. Fix: give that connection a non-empty send buffer so the guard-free path would throw, or drop the claim. An automated check would be mutation testing (Infection), which the repository does not run. | low | open |
| 3 | `tests/ServerWorkerTest.php:1251-1278` | R3-2: the test has no positive control, so removing the `armIdleConnectionSweeper()` call leaves it green. The neighbouring timeout tests catch that mutant. Add an expired connection and assert that it is closed. | nit | open |

Status of the earlier findings in round 3:

- R1-1 and R1-2: fixed.
- R2-1 and R2-2: fixed. The gate is unchanged apart from the ceiling, now 40.
- R2-3: still present (optional nit).

Behaviour preservation was verified independently:

- **`ServerWorker`:** side-by-side invariant table in `review-3.md`. Callback order, closure binding and static-ness, captures, `onWorkerStart` step order and exception handling are unchanged.
- **`kb-lint`:** 1,224 differential CLI runs of old against new script (300 mutated roots plus 6 edge roots, 4 modes each, stdout, stderr, exit code and the tree after `--fix` compared), with 0 differences, plus a directed root.

### Stage 2 resolution (maintainer)

- R3-1: fixed. The null-context connection now has a pending send buffer and `sweepConnection()` is also invoked directly; with the `instanceof \stdClass` guard removed the test errors ("Attempt to assign property "lastBytesWritten" on null"), with the guard it passes. (A guard violation inside the event-loop timer alone was swallowed, hence the direct call.)
- R3-2: not done (nit; other timeout tests prove the sweeper is armed).
