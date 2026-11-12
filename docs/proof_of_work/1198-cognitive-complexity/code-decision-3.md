# #1198 stage 2: cognitive-complexity function limit 50 → 40, round 3 (coder-high)

Branch `refactor/issue-1198-cognitive-complexity-40`, from master `0394183` (stage 1 merged as #1199).

## What changed

- `phpstan.neon.dist`: `cognitive_complexity.function: 40`. `class` stays at 150.
- `tests/CognitiveComplexityGateTest.php`: `FUNCTION_LIMIT_CEILING = 40`. The gate is otherwise unchanged.
- `src/Worker/ServerWorker.php`: `__construct()` keeps the worker setup and the order in which the four callbacks are assigned (`onClose`, `onConnect`, `onWorkerReload`, `onWorkerStart`). Each callback body now comes from a private method that returns the same closure:
  - `connectHandler()`, which uses `connectionErrorHandler()` for the `errorHandler` backstop
  - `workerReloadHandler()`
  - `workerStartHandler()`, which uses `armIdleConnectionSweeper()` and `messageHandler()`
  - `sweepConnection()`, which holds the body of the per-connection sweep loop
  - `restartBackoffKey()`, which removes the two copies of the `server:%s:%s:%d` key
- `bin/kb-lint.php`:
  - `parseFile()` delegates to a new `KbFileParser` class in the script. The mutable parse state (fence, index start, current entry, errors and so on) moved into its properties, and each line kind has its own method: `parseLine`, `closeIndex`, `appendToCurrentEntry`, `openHeading`, `withoutFrontMatterLine`, `countLines`.
  - The old by-reference `$currentBody` alias into `$entries` became an integer index (`currentEntry`). The dead variable `$current` was removed.
  - `parseFrontMatter()` now uses `readFrontMatterPair()`, `readQuotedFrontMatterValue()` and `frontMatterPairError()`.
  - `validateEntry()` (31, a stage-3 violator) was split as well, so `kb-lint` does not have to be reopened. It now spreads, in the original error order, `frontMatterKeyErrors`, `identityErrors` (id and date), `tagErrors`, `lifecycleErrors` (hits and status) and `promotedEntryErrors`.
- `tests/ServerWorkerTest.php`: characterisation tests, written before the refactor and run against the **original** class too:
  - `testOnConnectSetsBodySizeCap` used to check only that `onConnect` was set. It now calls `onConnect` and asserts that `maxPackageSize` changed from the default to 8192.
  - The new `testSweeperLeavesConnectionsWithoutContextOrLastActivityOpen` checks that the sweeper skips a connection with a `null` context and one whose `lastActivity` is not an int. Both stay `ESTABLISHED` after 2.2 s at 1 s timeouts.

  Before these tests, three statements were never executed by `ServerWorkerTest`: the body-cap assignment, the skip for a context that is not `stdClass`, and the skip for a `lastActivity` that is not an int. Now every statement of `ServerWorker` runs, per a Clover report of `ServerWorkerTest` alone.
- `CHANGELOG.md`: the existing `(#1198)` line under `[Unreleased] → Changed` was extended (40 per function, and the `ServerWorker` constructor added to the list). No second entry.
- `CONTRIBUTING.md`: "currently 40 per function or method and 150 per class".

## Scores (before → after)

| Function | Before | After |
|---|---|---|
| `ServerWorker::__construct()` | 48 | **1** |
| `bin/kb-lint.php parseFile()` | 41 | **0** (delegates; `KbFileParser` class total 24, max method `parseLine` 8) |
| `bin/kb-lint.php parseFrontMatter()` | 30 | **6** (`readFrontMatterPair` 9, others ≤ 2) |
| `bin/kb-lint.php validateEntry()` | 31 | **1** (helpers ≤ 6) |

New `ServerWorker` methods:
- `sweepConnection` 10
- `armIdleConnectionSweeper` 3
- `connectHandler`, `workerStartHandler` and `messageHandler` 2 each
- `__construct` 1

The `ServerWorker` class total is 35. Nothing resisted. The highest function in `kb-lint.php` is now `parseArgs()` at 13, so `kb-lint` is done for every stage up to a limit of 20.

## Remaining functions (after this PR, measured with a temporary config at 20 / 100, not committed)

**Above 30 (stage 3):**
- `GithubWorkflowsTest::testMatrixRewriteExclusionsCoverOffSchemeSymfonyPackages()` 36
- `bin/check-changelog.php validateChangelogLines()` 34
- `bin/check-upgrade-exceptions.php checkUpgradeExceptionsMain()` 32

**21–30 (stage 4, in addition):**
- 29 each:
  - `checkExceptionUsageDeclaredTypes()`
  - `WorkermanCompilerPass::prepareMiddlewares()`
  - `tests/Fixtures/sigchld_test_runner.php testSchedulerWorkerHandler()`
- 28 each:
  - `checkExceptionUsageMain()`
  - `NoNarrativeCommentsFixer::removeComment()`
- 27 each:
  - `SfxDownloader::removeExtractedEntries()`
  - `FileMonitorWatcher::globToRegex()`
- `SfxDownloader::fetch()` 26
- `BinaryFileResponseStrategy::scheduleFileCleanup()` 25
- 22 each:
  - `checkUpgradeExceptionsParseTree()`
  - `checkUpgradeExceptionsParseNamedTypes()`
  - `WorkermanCompilerPass::normalizeTaskTag()`
  - `RequestConverter::processFileNode()` (DEC-019 binds)
  - `InotifyMonitorWatcher::onNotify()`
  - `Runner::warmUpCache()`
- `PollingMonitorWatcher::checkFileSystemChanges()` 21

That is 16 functions. Stage 1 counted 17, and `parseFrontMatter` has now been taken.

**Classes above 100:**
- `SfxDownloader` 150 (exactly at the current limit)
- `tests/ProcessInspectorTest` 122
- `NoNarrativeCommentsFixer` 112

## How behaviour preservation was checked

- **ServerWorker.** The diff was checked invariant by invariant against the original:
  - **Callback wiring order** is identical, and every callback is still assigned during construction.
  - **Closure kind and `$this` binding** are unchanged. All closures that were non-static (`onClose`, `onConnect`, `errorHandler`, `onWorkerReload`, `onWorkerStart`, `onMessage`) are still created inside instance methods, so they are still bound to the `ServerWorker`. The sweeper timer callback is still a `static function`. Its body now calls `self::sweepConnection()`, a static method, so it captures no `$this`.
  - **Captured variables** are unchanged. `onConnect` still captures `$bodySizeCap` by that name, and `testOnConnectClosureCapturesBodySizeCap` reads it through `ReflectionFunction::getStaticVariables()`. `onWorkerStart` captures the same four values. `onMessage` captures `$handler`, and `errorHandler` captures `$connection`.
  - **The order of side effects in `onWorkerStart`** is unchanged:
    1. `Http::requestClass`
    2. arm the sweeper, but only when one of the timeouts is greater than 0
    3. `beforeStart` with the same key
    4. log
    5. create and boot the kernel
    6. `configureHandler`
    7. set `onMessage`
  - **Exception handling** is unchanged: the `onMessage` `try` / `finally` around `resetHeaders()` is the same, and no new `try`/`catch` was added.
  - **Sweeper loop:** `continue` became `return` inside the extracted per-connection method, with the same branch order. `$now` is still taken once per tick and shared by all connections.

  Tests: all 47 `ServerWorkerTest` cases, including the 2 strengthened or new ones, pass on both the original and the refactored class. Statement coverage of `ServerWorker` is now complete. The original also left 3 statements uncovered, and it was the same 3, at their old line numbers. A full `composer test` passes except FAQ-007.
- **kb-lint, differential.** I ran a throwaway harness, not committed, that loads the function part of the old and the new script in separate processes:
  - It calls `parseFrontMatter()` on 20,000 generated front-matter lines. The fragments include quoted, unterminated, duplicate and `-->` values, keys that are only `=`, bare keys, non-ASCII bytes and a missing `kb:`.
  - It calls `parseFile()` on 3,000 generated Markdown files. These mix headings at `#` to `####`, `#nospace`, fences, index start and end markers in any order, `## Tag index` headings, front matter, CRLF and LF line ends, and files with and without a trailing newline.
  - It calls `validateEntry()` on every parsed entry, with both the `FAQ` and the `DEC` prefix.
  - I compared `md5(serialize())` of the whole output. It was identical (`5fc67651…`), both before and after `lint-fix`. The run covered 15,126 front-matter errors, 3,284 entries, 5,240 parse errors and 1,454 validation errors.

  I also re-ran the stage-1 CLI harness: 21 knowledge-base roots × 5 modes, with stdout, stderr, exit code and the trees after `--fix` compared with `diff -r`. It was identical.

## Alternatives rejected

- **First-class callables** (`$this->onConnect(...)`) instead of methods that return closures. That would change the closure's static variables, so `bodySizeCap` would disappear from `ReflectionFunction::getStaticVariables()` and an existing test would fail. It would also change how the extracted state is passed. Returning the original closure literal keeps both the shape and the captures identical.
- **Passing sweeper arguments through `Timer::add(..., $args)`.** Workerman would then call the callback with arguments instead of captures. That works, but it is a different call shape, so I rejected it to keep the refactor pure.
- **A by-reference state array for `kb-lint` `parseFile()`.** It would need the full array shape repeated in every helper's docblock, and the script's `@phpstan-type Entry` is not attached to anything (stage-1 finding 4). A small `final class` with typed properties is clearer, and PHPStan checks it fully.
- **A two-pass `parseFile()`**, with a separate pass for index markers. The error order interleaves index and front-matter errors by line, so two passes would need a merge step. Rejected.

## What I am unsure about

- **`kb-lint.php` now declares a class** in a script that otherwise holds only functions. PHP-CS-Fixer, Rector and PHPStan accept it, and the script is run only as a subprocess, so there is no risk of redeclaring it. If the maintainers prefer function-only scripts, the alternative is the by-reference state array.
- **`$this->lines[$lineCount - 1]` replaces `end($lines)`.** `end()` cannot take a readonly property, because it needs a by-reference argument. This is equivalent because `readLines()` returns a list. The differential confirms it, including empty files.
- **`ServerWorker::sweepConnection()` is `private static`.** Rector's `LocallyCalledStaticMethodToNonStaticRector` left it static, because it is called from a static closure. If a later Rector version makes it non-static, the closure would have to lose `static` and capture `$this`. That would be harmless for lifetime, since the other callbacks already keep the `ServerWorker` alive, but it is a change worth noticing in review.
