# Round 1 — coder

- **Agent:** `coder`
- **Issue:** #662
- **Branch:** `fix/issue-662-inotifymonitorwatcher-mask-omits-in-move`

> Recorded originally as a PR comment by the machine-checked proof of work that
> #697 removed ([comment 5250217651](https://github.com/crazy-goat/workerman-bundle/pull/689#issuecomment-5250217651)).
> Moved here verbatim so the cycle's evidence lives in the format the workflow
> now documents.


Everything is complete and verified. Final report follows.

---

## 1. What changed (final state, commit `fc029b6`)

| File | Why |
|---|---|
| `src/Reboot/FileMonitorWatcher/InotifyMonitorWatcher.php` | Added `IN_MOVED_FROM` to the `inotify_add_watch()` mask; new `onNotify()` branch for `IN_MOVED_FROM\|IN_ISDIR` that drops stale bookkeeping via new private `forgetWatchedTree()`; file-move-out now falls through to the pattern check (reload like a deletion) |
| `tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` | Two new `@requires extension inotify` tests: `testMovedOutDirectoryIsDroppedAndRewatchedOnReturn` (acceptance criterion: moved-out dir + its watched children leave both maps, move-back re-watches, maps consistent) and `testMovedOutMatchingFileTriggersReload` |
| `CHANGELOG.md` | Unreleased entry for #662 |

I **critically reviewed** the aborted run's diff rather than retouching it: the branch placement (after `IN_IGNORED`, before `IN_MOVED_TO\|IN_ISDIR`), prefix-safe forgetting (`str_starts_with($path . '/')` avoids matching `a-b` when forgetting `a`), idempotency, the IN_IGNORED-with-unknown-wd no-op after a forget, intra-tree rename ordering (MOVED_FROM precedes MOVED_TO), the surviving-wd remap on move-back, and the file-reload path are all correct. The conscious decision to **not** add `IN_MOVE_SELF` (optional per the issue) is right: it fires between MOVED_FROM/MOVED_TO in intra-tree renames, so forgetting there could strand a re-mapped watch.

## 2. BIGGEST problem: the test environment, not the code

`composer test` failed ~6 times with `mkdir(): Read-only file system` at `Runner.php:167`, because `pid_file` resolved to `/app/var/run/workerman.pid`. Root cause, found by elimination (instrumented `Runner`/`ConfigLoader`, SIGSTOP'd the master, lsof'd the cache file):

- The **aborted run's Docker phase left a running `php:8.4-cli` container** (`b8f784ac4dfd`, started 08:44, repo mounted at `/app`) whose PHP processes kept booting the kernel and **rewriting `var/cache/{dev,test}/workerman/config.cache.php` through the mount every few seconds with literal `/app` paths**, plus a `.ser` container dump with `kernel.project_dir=/app`.
- Local daemon runs raced with those rewrites; partial cache deletions were recontaminated within seconds (the local daemon's supervisor `[Process]` workers boot and warm the config cache on every respawn, and `stop` itself boots+warms via `ServerManager`→`Runner`, so a poisoned cache also breaks `stop` — the only way out is pkill).
- The mystery "daemon rewrites after clean start" was the local `[Process]` worker loop racing the Docker VM (`com.apple.Virtualization.VirtualMachine`, 400+ FDs into `var/cache`) writing through the mount.

Solved by: `docker ps` → `docker kill` + `docker rm b8f784ac4dfd`; `pkill -9 -f 'WorkerMan:'` (see finding below); full `rm -rf var/cache var/run`; then `composer test` passed deterministically.

## 3. Discovered bugs / places to improve (outside scope)

- `docs/helpers/faq.md` FAQ-007 cleanup is **incomplete**: `pkill -9 -f 'tests/App/index.php'` does not match worker processes (cmdline `WorkerMan: worker process [Server] ...` has no `index.php`) — after a SIGKILLed master, orphaned workers survived and kept ports 8888/9999/9991 bound. Suggest documenting `pkill -9 -f 'WorkerMan:'` (after verifying no unrelated WorkerMan apps).
- `docs/helpers/faq.md` + repo hygiene: a Docker run mounting the repo at `/app` poisons `var/cache` for all later local runs (config cache + `.ser` get literal `/app` paths; `stop` fails too). No code fix warranted; a KB entry + cleanup discipline after container runs is enough.
- `src/Reboot/FileMonitorWatcher/InotifyMonitorWatcher.php:106-115` (pre-existing): events with an unknown wd whose basename matches the pattern (e.g. `IN_MODIFY` from an unrecorded watch) still schedule a reload. Harmless (reload is cheap) but slightly noisy; suggested fix: require `pathByWd[$event['wd']]` for pattern path too.
- `src/Worker/SupervisorWorker`/config flow (pre-existing): `[Process]` worker respawn loop (TestErrorProcess failing → respawn) re-warms and rewrites the config cache every few seconds — the amplification factor for the poison above; worth a targeted cache-freshness guard.

## 4. Validation performed

- `composer test` (full local, real daemon): **2111 tests, 21908 assertions, 31 skipped, 0 failures** — the two new inotify tests skip (no ext-inotify on macOS, PHP 8.5).
- `composer lint`: php-cs-fixer 0/243 fixable, PHPStan level 8 OK, Rector OK, kb-lint OK (check-pow advisory-only POW-02 pending = expected).
- Docker (one run, php:8.4-cli + pecl inotify + pcntl/posix, mounted at /app, `--rm`): **InotifyMonitorWatcherTest — 25 tests, 97 assertions, 1 skip (the inverse-skip `testStartIsNoOpWhenInotifyNotAvailable`), OK — both new tests pass with real inotify** on Linux.
- Pre-push hook: green; pushed `fc029b6` to `origin/fix/issue-662-inotifymonitorwatcher-mask-omits-in-move`. Post-push state: tree clean, no daemons, ports free, caches cleaned.

## 5. Candidate knowledge-base entries

- **Title:** "A Docker run mounting the repo at /app poisons local var/cache (pid_file resolves to /app/var/run, stop fails with EROFS)" — **tags:** docker, daemon, tests — **trigger:** local `composer test` fails with `mkdir(): Read-only file system` at Runner applyWorkermanConfig, or `php tests/App/index.php stop` cannot stop a running daemon. The mounted container keeps booting/warming caches through the mount, rewriting `config.cache.php`/`.ser` with literal `/app` paths; the local daemon's `[Process]` respawn loop amplifies it. Fix: `docker ps` → kill/rm the stuck container, `pkill -9 -f 'WorkerMan:'`, `php tests/App/index.php stop`, `rm -rf var/cache var/run`, then rerun — one paragraph.
- **Title:** "Orphaned Workerman workers survive `pkill -f 'tests/App/index.php'` and keep ports bound" — **tags:** daemon, tests, ports — **trigger:** after SIGKILLing a Workerman master, ports 8888/9999 stay busy though `ps | grep index.php` shows nothing. Worker cmdlines are `WorkerMan: worker process [Server] ...` without `index.php`; kill them with a repo-scoped `pkill -9 -f 'WorkerMan:'` instead — one paragraph.

## 6. Assumptions, limits, residual risks

- `IN_MOVE_SELF` intentionally not implemented (root source dir moved while daemon runs still leaves a stale map entry; events on the surviving watch still fire, so reloads keep working; the issue labeled this optional). Residual risk: watch-slot leak only for that one case.
- Asymmetry kept intentionally: a moved-out dir whose *name* matches the pattern does not schedule a reload, while a deleted one does (matches the issue's suggested fix; dir names rarely match `*.php`).
- The Docker verification ran the whole `InotifyMonitorWatcherTest` class (25 tests, incl. all #575-era inotify tests with the new mask) — not the full suite in Docker, per the bounded budget.
- If any future session runs Docker with this repo mounted, the `/app` cache-poisoning recurs; cleanup steps in §5 apply. The Docker VM process still holds stale host-side FDs but no longer writes (container removed).
