# Findings — coder (#662)

Recorded by the `coder` agent in round 1. Items marked *(out of scope)* are
things noticed while working on #662, not part of it.

| # | Where | What | Outcome |
| --- | --- | --- | --- |
| C-01 | environment | `composer test` failed ~6× with `mkdir(): Read-only file system` because `pid_file` resolved to `/app/var/run/workerman.pid`. A leftover `php:8.4-cli` container from an earlier aborted run had the repo mounted at `/app` and kept rewriting `var/cache/{dev,test}/workerman/config.cache.php` with literal `/app` paths through the mount. The local `[Process]` respawn loop re-warmed the poisoned cache within seconds of every cleanup, and `stop` itself boots the kernel, so a poisoned cache breaks `stop` too | resolved: killed and removed the container, `pkill -9 -f 'WorkerMan:'`, `rm -rf var/cache var/run`; tests then passed deterministically |
| C-02 | `docs/helpers/faq.md` FAQ-007 *(out of scope)* | the documented cleanup `pkill -9 -f 'tests/App/index.php'` does not match worker processes — their cmdline is `WorkerMan: worker process [Server] …` with no `index.php`, so orphaned workers survive a SIGKILLed master and keep 8888/9999/9991 bound | proposed knowledge-base entry; suggest documenting `pkill -9 -f 'WorkerMan:'` |
| C-03 | `src/Reboot/FileMonitorWatcher/InotifyMonitorWatcher.php:106-115` *(out of scope, pre-existing)* | an event with an unknown `wd` whose basename matches the pattern still schedules a reload. Harmless — a reload is cheap — but noisy | not fixed; suggested fix is to require `pathByWd[$event['wd']]` for the pattern path too |
| C-04 | `src/Worker/SupervisorWorker` / config flow *(out of scope, pre-existing)* | the `[Process]` worker respawn loop re-warms and rewrites the config cache every few seconds, which is what amplified C-01 from a one-off into a race that recontaminated every cleanup | not fixed; suggests a targeted cache-freshness guard |

## Decisions taken deliberately

- **`IN_MOVE_SELF` not implemented.** The issue marked it optional. It fires
  between `MOVED_FROM` and `MOVED_TO` during an intra-tree rename, so forgetting
  there could strand a re-mapped watch. Residual risk: a watch-slot leak when
  the *root* source directory is moved while the daemon runs. Events on the
  surviving watch still fire, so reloads keep working.
- **Asymmetry kept.** A moved-out directory whose name matches the pattern does
  not schedule a reload, while a deleted one does. This matches the fix the
  issue suggested, and directory names rarely match `*.php`.

## Candidate knowledge-base entries

Proposals only — the coder does not write to `docs/helpers/`.

1. **"A Docker run mounting the repo at `/app` poisons local `var/cache`"** —
   tags `docker`, `daemon`, `tests`; trigger: local `composer test` fails with
   `mkdir(): Read-only file system`, or `php tests/App/index.php stop` cannot
   stop a running daemon.
2. **"Orphaned Workerman workers survive `pkill -f 'tests/App/index.php'`"** —
   tags `daemon`, `tests`, `ports`; trigger: after SIGKILLing a master, ports
   stay busy though `ps | grep index.php` shows nothing.
